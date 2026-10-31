<?php

/**
 * Работа с зоной: чтение, снимок, план изменений, применение.
 *
 * Правила, заложенные намеренно:
 *  - любое изменение начинается со снимка зоны (BIND-файл + JSON) — откат
 *    возможен даже если запись удалили по ошибке;
 *  - удаление записи принимается только по числовому id и только для
 *    A/AAAA/CNAME/TXT/MX/SRV/CAA: SOA и NS зоны не удаляются из панели;
 *  - план сначала показывается человеку, применение — отдельное действие.
 *
 * @package FornexPanel
 */

declare(strict_types=1);

namespace FornexPanel\Service;

use FornexPanel\Fornex\Client;
use FornexPanel\Fornex\FornexException;
use FornexPanel\Kernel\HttpException;
use FornexPanel\Kernel\Logger;
use FornexPanel\Support\Json;
use FornexPanel\Support\Str;

final class ZoneService
{
    public function __construct(
        private readonly Client $client,
        private readonly AuditService $audit,
        private readonly Logger $logger,
        private readonly string $snapshotDir,
    ) {
    }

    /**
     * Записи зоны в нормализованном виде.
     *
     * @return list<array{id: int, host: string, type: string, value: string, prio: ?int, ttl: ?int}>
     */
    public function entries(string $domain): array
    {
        $zone = $this->client->zone($domain);
        $rows = [];

        foreach ($zone['entries'] as $entry) {
            $rows[] = [
                'id' => (int) ($entry['id'] ?? 0),
                'host' => Str::host((string) ($entry['host'] ?? '')),
                'type' => strtoupper((string) ($entry['type'] ?? '')),
                'value' => (string) ($entry['value'] ?? ''),
                'prio' => isset($entry['prio']) && $entry['prio'] !== null ? (int) $entry['prio'] : null,
                'ttl' => isset($entry['ttl']) && $entry['ttl'] !== null ? (int) $entry['ttl'] : null,
            ];
        }

        return $rows;
    }

    /** @return array<string, int> количество записей по типам */
    public function summary(string $domain): array
    {
        $counts = [];

        foreach ($this->entries($domain) as $entry) {
            $counts[$entry['type']] = ($counts[$entry['type']] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }

    /**
     * Снимок зоны до изменения: BIND-файл от Fornex плюс наш JSON с id.
     *
     * @return array{path: string, sha256: string, entries: int, created_at: string}
     */
    public function snapshot(string $domain, string $kind = 'manual', string $note = ''): array
    {
        $entries = $this->entries($domain);
        $zoneFile = '';

        try {
            $zoneFile = $this->client->zoneFile($domain);
        } catch (FornexException $exception) {
            $this->logger->warning('файл зоны недоступен, снимок делаю по записям', [
                'domain' => $domain,
                'status' => $exception->status,
            ]);
        }

        $directory = rtrim($this->snapshotDir, '/') . '/' . $domain;

        if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw HttpException::upstream('Не удалось создать каталог снимков: ' . $directory);
        }

        $stamp = gmdate('Ymd-His');
        $base = $directory . '/' . $stamp . '-' . preg_replace('/[^a-z0-9-]+/i', '-', $kind);

        $payload = [
            'domain' => $domain,
            'kind' => $kind,
            'note' => $note,
            'created_at' => gmdate('c'),
            'entries' => $entries,
            'zone_file' => $zoneFile,
        ];

        $json = Json::encode($payload);
        file_put_contents($base . '.json', $json, LOCK_EX);

        if ($zoneFile !== '') {
            file_put_contents($base . '.zone', $zoneFile, LOCK_EX);
        }

        $meta = [
            'path' => $base . '.json',
            'sha256' => hash('sha256', $json),
            'entries' => count($entries),
            'created_at' => gmdate('c'),
        ];

        $this->audit->record(
            actor: 'system',
            action: 'zone.snapshot',
            target: $domain,
            summary: sprintf('снимок зоны (%s), записей %d', $kind, count($entries)),
            payload: $meta,
        );

        return $meta;
    }

    /**
     * Сравнивает желаемые записи с текущими. Расчёт вынесен в ZonePlanner,
     * чтобы правило «заменять, а не дублировать» проверялось тестом.
     *
     * @param list<array<string, mixed>> $desired
     * @return array{create: list<array<string, mixed>>, update: list<array<string, mixed>>, skip: list<array<string, mixed>>, conflict: list<array<string, mixed>>}
     */
    public function plan(string $domain, array $desired): array
    {
        return ZonePlanner::plan($this->entries($domain), $desired);
    }

    /**
     * Применение плана. $dryRun=true печатает намерения и ничего не пишет.
     *
     * @param array{create: list<array<string, mixed>>, update: list<array<string, mixed>>, skip: list<array<string, mixed>>, conflict: list<array<string, mixed>>} $plan
     * @return array<string, mixed>
     */
    public function applyPlan(string $domain, array $plan, bool $dryRun, string $actor, string $note = ''): array
    {
        $result = [
            'domain' => $domain,
            'dry_run' => $dryRun,
            'created' => [],
            'updated' => [],
            'skipped' => count($plan['skip']),
            'conflicts' => count($plan['conflict']),
            'errors' => [],
            'snapshot' => null,
        ];

        if ($dryRun) {
            $result['planned'] = [
                'create' => $plan['create'],
                'update' => $plan['update'],
            ];

            $this->audit->record($actor, 'zone.apply', $domain, sprintf(
                'сухой прогон: создать %d, изменить %d, пропустить %d, конфликтов %d',
                count($plan['create']),
                count($plan['update']),
                count($plan['skip']),
                count($plan['conflict']),
            ), true, 'plan', $result['planned']);

            return $result;
        }

        if ($plan['create'] === [] && $plan['update'] === []) {
            $result['note'] = 'изменений нет';

            return $result;
        }

        $result['snapshot'] = $this->snapshot($domain, 'before-apply', $note);

        foreach ($plan['create'] as $item) {
            try {
                $created = $this->client->createEntry($domain, $this->payload($item));
                $result['created'][] = [
                    'id' => (int) ($created['id'] ?? 0),
                    'host' => $item['host'],
                    'type' => $item['type'],
                    'value' => Str::mask((string) $item['value']),
                    'why' => $item['why'],
                ];
            } catch (FornexException $exception) {
                $result['errors'][] = [
                    'host' => $item['host'],
                    'type' => $item['type'],
                    'status' => $exception->status,
                    'detail' => $exception->detail,
                ];
            }
        }

        foreach ($plan['update'] as $item) {
            try {
                $this->client->updateEntry($domain, (int) $item['id'], $this->payload($item));
                $result['updated'][] = [
                    'id' => (int) $item['id'],
                    'host' => $item['host'],
                    'type' => $item['type'],
                    'value' => Str::mask((string) $item['value']),
                    'prio' => $item['prio'],
                ];
            } catch (FornexException $exception) {
                $result['errors'][] = [
                    'id' => $item['id'],
                    'status' => $exception->status,
                    'detail' => $exception->detail,
                ];
            }
        }

        $this->audit->record($actor, 'zone.apply', $domain, sprintf(
            'создано %d, изменено %d, пропущено %d, ошибок %d',
            count($result['created']),
            count($result['updated']),
            (int) $result['skipped'],
            count($result['errors']),
        ), false, $result['errors'] === [] ? 'ok' : 'partial', $result);

        return $result;
    }

    /** @param array<string, mixed> $record */
    public function addEntry(string $domain, array $record, string $actor): array
    {
        $payload = $this->payload($record);
        $created = $this->client->createEntry($domain, $payload);

        $this->audit->record($actor, 'entry.create', $domain, sprintf(
            'создана запись %s %s',
            $payload['type'],
            Str::mask($payload['value']),
        ), false, 'ok', ['record' => $payload, 'id' => $created['id'] ?? null]);

        return $created;
    }

    /** @param array<string, mixed> $record */
    public function updateEntry(string $domain, int $id, array $record, string $actor): array
    {
        $current = $this->find($domain, $id);

        if ($current === null) {
            throw HttpException::notFound('Запись с таким id в зоне не найдена: ' . $id);
        }

        if (in_array($current['type'], ['SOA'], true)) {
            throw HttpException::forbidden('Запись SOA не редактируется из панели.');
        }

        $payload = $this->payload($record);
        $updated = $this->client->updateEntry($domain, $id, $payload);

        $this->audit->record($actor, 'entry.update', $domain, sprintf(
            'id %d: %s → %s',
            $id,
            Str::mask($current['value']),
            Str::mask($payload['value']),
        ), false, 'ok', ['before' => $current, 'after' => $payload]);

        return $updated;
    }

    public function deleteEntry(string $domain, int $id, string $actor): void
    {
        $current = $this->find($domain, $id);

        if ($current === null) {
            throw HttpException::notFound('Запись с таким id в зоне не найдена: ' . $id);
        }

        if (in_array($current['type'], ['SOA', 'NS'], true)) {
            throw HttpException::forbidden('Записи SOA и NS не удаляются из панели: они держат зону.');
        }

        $this->snapshot($domain, 'before-delete', 'удаление записи id ' . $id);
        $this->client->deleteEntry($domain, $id);

        $this->audit->record($actor, 'entry.delete', $domain, sprintf(
            'удалена запись id %d: %s %s',
            $id,
            $current['type'],
            Str::mask($current['value']),
        ), false, 'ok', ['record' => $current]);
    }

    /** @return array<string, mixed>|null */
    public function find(string $domain, int $id): ?array
    {
        foreach ($this->entries($domain) as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private function payload(array $record): array
    {
        $type = Str::type($record['type'] ?? '');

        if ($type === '') {
            throw HttpException::badRequest('Тип записи не поддерживается: ' . (string) ($record['type'] ?? ''));
        }

        $value = Str::value($record['value'] ?? '');

        if ($value === '') {
            throw HttpException::badRequest('Значение записи пустое.');
        }

        $payload = [
            'host' => Str::host($record['host'] ?? ''),
            'type' => $type,
            'value' => $value,
        ];

        if ($type === 'MX' || $type === 'SRV') {
            $prio = $record['prio'] ?? 0;
            $payload['prio'] = is_numeric($prio) ? (int) $prio : 0;
        }

        $ttl = $record['ttl'] ?? null;
        if ($ttl !== null && $ttl !== '' && is_numeric($ttl)) {
            $payload['ttl'] = (int) $ttl;
        }

        return $payload;
    }
}
