<?php

/**
 * Пресеты записей: «почта Google», «Яндекс 360», «сайт на VPS», «почта на VPS».
 *
 * Пресет — данные (config/presets.json), а не код: добавить новый набор можно,
 * не трогая PHP. Панель, CLI и внешние скрипты читают один и тот же файл,
 * поэтому «как в панели» и «как в скрипте» не расходятся.
 *
 * Пресет может состоять из ШАГОВ (steps). У Google это принципиально: DKIM
 * нельзя получить, пока домен не подтверждён, поэтому мастер ведёт по шагам,
 * а не выдаёт один список из шести записей.
 *
 * @package FornexPanel
 */

declare(strict_types=1);

namespace FornexPanel\Service;

use FornexPanel\Kernel\HttpException;
use FornexPanel\Support\Json;
use FornexPanel\Support\Str;

final class PresetService
{
    /** @var array<string, array<string, mixed>> */
    private array $presets = [];

    public function __construct(string $presetFile, private readonly string $defaultIp = '')
    {
        $data = Json::readFile($presetFile);

        if ($data === null) {
            throw new \RuntimeException('Не удалось прочитать пресеты: ' . $presetFile);
        }

        $this->presets = $data;
    }

    /**
     * Список пресетов для интерфейса: параметры с подставленными значениями
     * по умолчанию, чтобы форма открывалась заполненной.
     *
     * @return list<array<string, mixed>>
     */
    public function all(?string $domain = null): array
    {
        $result = [];

        foreach ($this->presets as $id => $preset) {
            $result[] = [
                'id' => $id,
                'title' => (string) ($preset['title'] ?? $id),
                'summary' => (string) ($preset['summary'] ?? ''),
                'applies_to' => (string) ($preset['applies_to'] ?? ''),
                'stepwise' => isset($preset['steps']),
                'steps' => array_map(
                    static fn (array $step): array => [
                        'id' => (string) ($step['id'] ?? ''),
                        'title' => (string) ($step['title'] ?? ''),
                        'requires' => $step['requires'] ?? [],
                    ],
                    $preset['steps'] ?? [],
                ),
                'params' => array_map(
                    fn (array $param): array => [
                        'key' => (string) $param['key'],
                        'label' => (string) ($param['label'] ?? $param['key']),
                        'type' => (string) ($param['type'] ?? 'text'),
                        'required' => (bool) ($param['required'] ?? false),
                        'hint' => (string) ($param['hint'] ?? ''),
                        'options' => $param['options'] ?? [],
                        'default' => $this->substitute((string) ($param['default'] ?? ''), $domain ?? '', []),
                    ],
                    $preset['params'] ?? [],
                ),
                'records' => array_map(
                    static fn (array $record): array => [
                        'host' => (string) ($record['host'] ?? ''),
                        'type' => (string) ($record['type'] ?? ''),
                        'value' => (string) ($record['value'] ?? ''),
                        'prio' => $record['prio'] ?? null,
                        'ttl' => $record['ttl'] ?? null,
                        'why' => (string) ($record['why'] ?? ''),
                        'conditional' => isset($record['when']),
                    ],
                    $preset['records'] ?? [],
                ),
            ];
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public function get(string $id): array
    {
        if (!isset($this->presets[$id])) {
            throw HttpException::notFound('Пресет не найден: ' . $id);
        }

        return $this->presets[$id];
    }

    /**
     * Собирает конкретные записи для домена с учётом параметров.
     *
     * @param array<string, mixed> $params
     * @return array{id: string, title: string, records: list<array<string, mixed>>, notes: list<string>, step: ?string}
     */
    public function build(string $id, string $domain, array $params, ?string $stepId = null, bool $strict = true): array
    {
        $preset = $this->get($id);
        $values = $this->resolveParams($preset, $domain, $params);

        $steps = $this->steps($preset);
        $records = [];
        $notes = [];

        if ($stepId !== null && !isset($steps[$stepId])) {
            throw HttpException::notFound('В пресете нет шага: ' . $stepId);
        }

        foreach ($steps as $stepKey => $step) {
            if ($stepId !== null && $stepKey !== $stepId) {
                continue;
            }

            $requiredParam = $step['requires_param'] ?? null;

            if (is_string($requiredParam) && $requiredParam !== '' && ($values[$requiredParam] ?? '') === '') {
                $label = $this->paramLabel($preset, $requiredParam);

                if ($stepId !== null && $strict) {
                    throw HttpException::badRequest('Шаг «' . (string) ($step['title'] ?? $stepKey) . '» без поля «' . $label . '» записей не даёт.', [
                        'missing' => [$label],
                    ]);
                }

                // Предпросмотр не падает: он объясняет, чего не хватает.
                $notes[] = 'Нужно заполнить: ' . $label;

                continue;
            }

            foreach ($step['records'] ?? [] as $record) {
                $resolved = $this->resolveRecord($record, $domain, $values);

                if ($resolved !== null) {
                    $records[] = $resolved;
                }
            }
        }

        if ($records === []) {
            $notes[] = 'ни одной записи не собрано: проверьте параметры';
        }

        return [
            'id' => $id,
            'title' => (string) ($preset['title'] ?? $id),
            'records' => $records,
            'notes' => $notes,
            'step' => $stepId,
        ];
    }

    /**
     * Нужно ли подтверждение именем домена для шага.
     *
     * Это свойство данных (presets.json): шаги, которые только добавляют записи
     * (MX/SPF, DMARC), применяются без ввода имени; шаги, меняющие подтверждение
     * владения или ключ подписи, требуют явного подтверждения.
     */
    public function requiresConfirm(string $presetId, ?string $stepId): bool
    {
        $preset = $this->get($presetId);

        if ($stepId === null) {
            return (bool) ($preset['confirm'] ?? true);
        }

        $steps = $this->steps($preset);

        if (!isset($steps[$stepId])) {
            return true;
        }

        return (bool) ($steps[$stepId]['confirm'] ?? $preset['confirm'] ?? true);
    }

    /**
     * Состояние шагов пресета: что уже в зоне, что заблокировано порядком.
     *
     * @param array<string, mixed> $params
     * @param list<array{id: int, host: string, type: string, value: string, prio: ?int, ttl: ?int}> $existing
     * @return array<string, mixed>
     */
    public function stepsOverview(string $id, string $domain, array $params, array $existing): array
    {
        $preset = $this->get($id);
        $values = $this->resolveParams($preset, $domain, $params, false);

        $result = [];
        $statusById = [];

        foreach ($this->steps($preset) as $stepKey => $step) {
            $records = [];

            foreach ($step['records'] ?? [] as $record) {
                $resolved = $this->resolveRecord($record, $domain, $values);

                if ($resolved !== null) {
                    $records[] = $resolved;
                }
            }

            $plan = ZonePlanner::plan($existing, $records);
            $changes = count($plan['create']) + count($plan['update']);
            $missingParam = $this->missingStepParam($preset, $step, $values);
            $present = $this->stepPresent($step, $existing);

            if ($records === [] || $missingParam !== null) {
                // Строку подтверждения и DKIM-ключ панель знать не может, но запись
                // в зоне видит: если она есть — шаг выполнен, а не «нет данных».
                $status = $present ? 'done' : 'empty';
            } elseif ($changes === 0 && $plan['conflict'] === []) {
                $status = 'done';
            } else {
                $status = 'todo';
            }

            $requires = array_values(array_filter((array) ($step['requires'] ?? []), 'is_string'));
            $blockedBy = [];

            foreach ($requires as $requirement) {
                if (($statusById[$requirement] ?? 'todo') !== 'done' && $status !== 'done') {
                    $blockedBy[] = $requirement;
                }
            }

            if ($blockedBy !== []) {
                $status = 'blocked';
            }

            $statusById[$stepKey] = $status;

            $blockedInfo = [];

            foreach ($blockedBy as $requirementKey) {
                $blockedInfo[] = [
                    'id' => $requirementKey,
                    'title' => $this->stepTitle($preset, $requirementKey),
                ];
            }

            $result[] = [
                'id' => $stepKey,
                'title' => (string) ($step['title'] ?? $stepKey),
                'summary' => (string) ($step['summary'] ?? ''),
                'hint' => (string) ($step['hint'] ?? ''),
                'requires' => $requires,
                'blocked_by' => $blockedInfo,
                'missing_param' => $missingParam,
                'present' => $present,
                'confirm' => $this->requiresConfirm($id, $stepKey),
                'status' => $status,
                'fields' => $this->stepFields($preset, $step),
                'records' => array_map(static fn (array $record): array => [
                    'host' => $record['host'],
                    'type' => $record['type'],
                    'value' => Str::mask((string) $record['value'], 80),
                    'prio' => $record['prio'],
                    'ttl' => $record['ttl'],
                    'why' => $record['why'],
                ], $records),
                'counts' => [
                    'create' => count($plan['create']),
                    'update' => count($plan['update']),
                    'skip' => count($plan['skip']),
                    'conflict' => count($plan['conflict']),
                ],
                'conflicts' => $plan['conflict'],
            ];
        }

        return [
            'id' => $id,
            'title' => (string) ($preset['title'] ?? $id),
            'summary' => (string) ($preset['summary'] ?? ''),
            'domain' => $domain,
            'steps' => $result,
            'done' => count(array_filter($result, static fn (array $step): bool => $step['status'] === 'done')) === count($result),
        ];
    }

    /**
     * Шаги пресета в едином виде. У пресета без steps — один неявный шаг.
     *
     * @param array<string, mixed> $preset
     * @return array<string, array<string, mixed>>
     */
    private function steps(array $preset): array
    {
        if (isset($preset['steps']) && is_array($preset['steps']) && $preset['steps'] !== []) {
            $steps = [];

            foreach ($preset['steps'] as $step) {
                $key = (string) ($step['id'] ?? '');

                if ($key !== '') {
                    $steps[$key] = $step;
                }
            }

            return $steps;
        }

        return ['main' => ['id' => 'main', 'title' => (string) ($preset['title'] ?? 'main'), 'records' => $preset['records'] ?? []]];
    }

    /**
     * Правило present_if: признак того, что шаг уже выполнен в зоне,
     * даже когда его параметр (строка подтверждения, DKIM-ключ) неизвестен.
     *
     * @param array<string, mixed> $step
     * @param list<array<string, mixed>> $existing
     */
    private function stepPresent(array $step, array $existing): bool
    {
        $rule = $step['present_if'] ?? null;

        if (!is_array($rule)) {
            return false;
        }

        $type = strtoupper((string) ($rule['type'] ?? ''));
        $hostName = Str::host((string) ($rule['host'] ?? ''));
        $prefix = strtolower((string) ($rule['prefix'] ?? ''));

        foreach ($existing as $row) {
            if ($row['type'] !== $type || $row['host'] !== $hostName) {
                continue;
            }

            $value = strtolower(ltrim((string) $row['value'], '"'));

            if ($prefix === '' || str_starts_with($value, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $preset
     * @param array<string, string> $values
     */
    private function missingStepParam(array $preset, array $step, array $values): ?string
    {
        $key = $step['requires_param'] ?? null;

        if (!is_string($key) || $key === '') {
            return null;
        }

        return ($values[$key] ?? '') === '' ? $this->paramLabel($preset, $key) : null;
    }

    /**
     * Поля формы для шага: либо перечисленные в шаге, либо все параметры пресета.
     *
     * @param array<string, mixed> $preset
     * @param array<string, mixed> $step
     * @return list<array<string, mixed>>
     */
    private function stepFields(array $preset, array $step): array
    {
        // У пресета с шагами поле принадлежит только своему шагу: «Строка подтверждения»
        // не должна всплывать на шаге MX, где она уже не нужна. У плоского пресета
        // (без steps) поля показываем все — там шаг один.
        $stepwise = isset($preset['steps']) && is_array($preset['steps']) && $preset['steps'] !== [];
        $wanted = $step['params'] ?? [];
        $fields = [];

        foreach ($preset['params'] ?? [] as $param) {
            $key = (string) ($param['key'] ?? '');

            if ($key === '') {
                continue;
            }

            if ($stepwise && !in_array($key, (array) $wanted, true)) {
                continue;
            }

            $fields[] = [
                'key' => $key,
                'label' => (string) ($param['label'] ?? $key),
                'type' => (string) ($param['type'] ?? 'text'),
                'required' => (bool) ($param['required'] ?? false),
                'hint' => (string) ($param['hint'] ?? ''),
                'options' => $param['options'] ?? [],
                'default' => (string) ($param['default'] ?? ''),
            ];
        }

        return $fields;
    }

    /** @param array<string, mixed> $preset */
    private function stepTitle(array $preset, string $stepId): string
    {
        foreach ($preset['steps'] ?? [] as $step) {
            if ((string) ($step['id'] ?? '') === $stepId) {
                return (string) ($step['title'] ?? $stepId);
            }
        }

        return $stepId;
    }

    /** @param array<string, mixed> $preset */
    private function paramLabel(array $preset, string $key): string
    {
        foreach ($preset['params'] ?? [] as $param) {
            if ((string) ($param['key'] ?? '') === $key) {
                return (string) ($param['label'] ?? $key);
            }
        }

        return $key;
    }

    /**
     * @param array<string, mixed> $preset
     * @param array<string, mixed> $params
     * @return array<string, string>
     */
    private function resolveParams(array $preset, string $domain, array $params, bool $strict = true): array
    {
        $values = [];
        $missing = [];

        foreach ($preset['params'] ?? [] as $param) {
            $key = (string) $param['key'];
            $raw = $params[$key] ?? null;
            $value = is_scalar($raw) ? trim((string) $raw) : '';

            if ($value === '') {
                $value = $this->substitute((string) ($param['default'] ?? ''), $domain, $values);
            }

            if ($value === '' && ($param['required'] ?? false) === true) {
                $missing[] = (string) ($param['label'] ?? $key);

                continue;
            }

            $values[$key] = $value;
        }

        if ($strict && $missing !== []) {
            throw HttpException::badRequest('Заполните обязательные поля: ' . implode(', ', $missing), [
                'missing' => $missing,
            ]);
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, string> $values
     * @return array<string, mixed>|null
     */
    private function resolveRecord(array $record, string $domain, array $values): ?array
    {
        $condition = $record['when'] ?? null;

        if (is_string($condition) && $condition !== '') {
            $current = $values[$condition] ?? '';

            if ($current === '') {
                return null;
            }

            if (isset($record['when_value']) && (string) $record['when_value'] !== $current) {
                return null;
            }
        }

        $value = $this->substitute((string) ($record['value'] ?? ''), $domain, $values);

        if ($value === '') {
            return null;
        }

        $value = $this->normalizeValue(
            strtoupper((string) ($record['type'] ?? '')),
            $this->substitute((string) ($record['host'] ?? ''), $domain, $values),
            $value,
        );

        return [
            'host' => Str::host($this->substitute((string) ($record['host'] ?? ''), $domain, $values)),
            'type' => strtoupper((string) ($record['type'] ?? '')),
            'value' => $value,
            'prio' => $record['prio'] ?? null,
            'ttl' => $record['ttl'] ?? null,
            'why' => (string) ($record['why'] ?? ''),
        ];
    }

    /**
     * Приведение значений, которые люди копируют из консолей.
     *
     * DKIM: в Консоли администратора ключ показывается целиком, но часто копируют
     * только часть `p=MIIB…` — без `v=DKIM1; k=rsa;` такая TXT-запись невалидна,
     * и почта останется без подписи. Переносы строк внутри ключа тоже ломают запись.
     * Подтверждение Google: убираем переносы и лишние пробелы.
     */
    private function normalizeValue(string $type, string $hostName, string $value): string
    {
        if ($type !== 'TXT') {
            return $value;
        }

        // Переносы строк и табы → одиночный пробел: они появляются при копировании.
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        if (str_ends_with(strtolower($hostName), '_domainkey')) {
            $lower = strtolower($value);

            if (!str_starts_with($lower, 'v=dkim1')) {
                if (str_starts_with($lower, 'p=')) {
                    $value = 'v=DKIM1; k=rsa; ' . $value;
                } elseif (preg_match('#^[A-Za-z0-9+/=]+$#', $value) === 1) {
                    $value = 'v=DKIM1; k=rsa; p=' . $value;
                }
            }

            // Внутри base64 пробелов быть не должно: перенос строки при копировании
            // иначе попадёт в значение записи и подпись перестанет проверяться.
            if (preg_match('/\bp=([^;]+)/i', $value, $matches) === 1) {
                $clean = (string) preg_replace('/\s+/', '', $matches[1]);
                $value = (string) preg_replace('/\bp=[^;]+/i', 'p=' . $clean, $value, 1);
            }
        }

        return $value;
    }

    /**
     * Подстановка {domain}, {default_ip} и значений параметров.
     *
     * @param array<string, string> $values
     */
    private function substitute(string $template, string $domain, array $values): string
    {
        $map = $values;
        $map['domain'] = $domain;
        $map['default_ip'] = $this->defaultIp;

        $result = $template;

        foreach ($map as $key => $value) {
            $result = str_replace('{' . $key . '}', (string) $value, $result);
        }

        return trim($result);
    }
}
