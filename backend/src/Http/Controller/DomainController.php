<?php

/**
 * Домены: список, карточка, добавление зоны, удаление зоны.
 *
 * Добавление домена — единственная операция, которая может быть запрещена
 * на стороне Fornex (по умолчанию право выключено). Поэтому вместо «упало
 * с 403» панель отдаёт готовый путь: текст тикета в поддержку и ссылку в
 * личный кабинет, а план записей сохраняется и применяется позже одной кнопкой.
 *
 * @package FornexPanel
 */

declare(strict_types=1);

namespace FornexPanel\Http\Controller;

use FornexPanel\Fornex\FornexException;
use FornexPanel\Kernel\HttpException;
use FornexPanel\Kernel\Request;
use FornexPanel\Kernel\Response;
use FornexPanel\Support\Str;

final class DomainController extends Controller
{
    public function index(Request $request): Response
    {
        $domains = [];
        $error = null;

        try {
            foreach ($this->s->client->domains() as $row) {
                $entries = is_array($row['entry_set'] ?? null) ? $row['entry_set'] : [];
                $counts = [];
                $apex = null;

                foreach ($entries as $entry) {
                    $type = strtoupper((string) ($entry['type'] ?? ''));
                    $counts[$type] = ($counts[$type] ?? 0) + 1;

                    if ($type === 'A' && Str::host((string) ($entry['host'] ?? '')) === '' && $apex === null) {
                        $apex = (string) ($entry['value'] ?? '');
                    }
                }

                ksort($counts);

                $domains[] = [
                    'name' => (string) ($row['name'] ?? ''),
                    'created' => (string) ($row['created'] ?? ''),
                    'updated' => (string) ($row['updated'] ?? ''),
                    'entries' => count($entries),
                    'types' => $counts,
                    'apex' => $apex,
                    'has_mx' => isset($counts['MX']),
                    'has_spf' => $this->hasSpf($entries),
                ];
            }
        } catch (FornexException $exception) {
            $error = $exception->detail;
            $this->s->logger->error('список доменов Fornex недоступен', [
                'status' => $exception->status,
                'detail' => $exception->detail,
            ]);
        }

        usort($domains, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $this->ok($request, [
            'domains' => $domains,
            'count' => count($domains),
            'error' => $error,
        ]);
    }

    public function show(Request $request, array $params): Response
    {
        $domain = $this->domain($params);
        $entries = $this->s->zone->entries($domain);

        $counts = [];
        foreach ($entries as $entry) {
            $counts[$entry['type']] = ($counts[$entry['type']] ?? 0) + 1;
        }
        ksort($counts);

        return $this->ok($request, [
            'domain' => $domain,
            'entries' => $entries,
            'types' => $counts,
            'snapshots' => count($this->s->audit->snapshots($domain, 20)),
            'events' => $this->s->audit->list(10, $domain),
        ]);
    }

    /** Создание зоны. При 403 от Fornex отдаём инструкцию, а не голую ошибку. */
    public function add(Request $request): Response
    {
        $domain = Str::domain($request->string('name'));

        if ($domain === null) {
            throw HttpException::badRequest('Некорректное имя домена.');
        }

        $ip = $request->string('ip', $this->s->config->string('fornex.default_ip'));

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw HttpException::badRequest('Нужен IPv4-адрес для начальной A-записи зоны.');
        }

        try {
            $created = $this->s->client->createDomain($domain, $ip);
        } catch (FornexException $exception) {
            if ($exception->isZoneCreateForbidden()) {
                $this->s->audit->record($this->s->auth->actor(), 'domain.add', $domain, 'отказ Fornex: создание зон через API выключено', false, 'denied', [
                    'ip' => $ip,
                ]);

                throw new HttpException(409, 'zone_create_forbidden', 'У аккаунта Fornex выключено право создавать зоны через API.', [
                    'domain' => $domain,
                    'ip' => $ip,
                    'ticket' => $this->ticketText($domain),
                    'cabinet' => 'https://ru.fornex.com/my/dns/',
                    'what_to_do' => [
                        '1. В личном кабинете Fornex: «DNS» → «Добавить домен» → ' . $domain . ' (A-запись ' . $ip . ').',
                        '2. Либо отправьте тикет в поддержку с текстом ниже — право включат для аккаунта.',
                        '3. После появления зоны вернитесь сюда: пресеты и проверка заработают без ожидания.',
                    ],
                ]);
            }

            throw new HttpException(502, 'fornex_error', 'Fornex не создал зону: ' . $exception->detail, $exception->toArray());
        }

        $this->s->audit->record($this->s->auth->actor(), 'domain.add', $domain, 'зона создана через API', false, 'ok', [
            'ip' => $ip,
        ]);

        return $this->ok($request, ['domain' => $domain, 'created' => $created]);
    }

    /**
     * Удаление зоны — необратимо и уносит все записи. Требует подтверждения
     * именем домена; перед удалением делается снимок.
     */
    public function destroy(Request $request, array $params): Response
    {
        $domain = $this->domain($params);
        $confirm = $request->string('confirm');

        if ($confirm !== $domain) {
            throw HttpException::conflict('Удаление зоны требует подтверждения: введите имя домена в поле подтверждения.', [
                'expected' => $domain,
            ]);
        }

        $snapshot = $this->s->zone->snapshot($domain, 'before-zone-delete', 'удаление зоны');

        if ($request->bool('dry_run')) {
            return $this->ok($request, ['dry_run' => true, 'domain' => $domain, 'snapshot' => $snapshot]);
        }

        $this->s->client->deleteDomain($domain);

        $this->s->audit->record($this->s->auth->actor(), 'domain.delete', $domain, 'зона удалена', false, 'ok', [
            'snapshot' => $snapshot['path'],
        ]);

        return $this->ok($request, ['domain' => $domain, 'deleted' => true, 'snapshot' => $snapshot]);
    }

    /**
     * @param list<array<string, mixed>> $entries
     */
    private function hasSpf(array $entries): bool
    {
        foreach ($entries as $entry) {
            if (strtoupper((string) ($entry['type'] ?? '')) === 'TXT'
                && str_starts_with(strtolower((string) ($entry['value'] ?? '')), 'v=spf1')) {
                return true;
            }
        }

        return false;
    }

    private function ticketText(string $domain): string
    {
        return <<<TEXT
Прошу включить для моего аккаунта возможность добавления DNS-доменов через Public API
(POST /dns/domain/). Сейчас запрос возвращает 403 «You do not have permission to perform this action».

Зачем: домен {$domain} и последующие домены аккаунта ведутся из собственной панели,
которая создаёт зону и прописывает записи (A, MX, TXT для почты Google) через API.
Без этого права зону приходится создавать вручную в личном кабинете.

Аккаунт: <ваш логин>. Домен: {$domain}.
TEXT;
    }
}
