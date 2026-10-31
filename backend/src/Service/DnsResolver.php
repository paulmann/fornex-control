<?php

/**
 * DNS-запросы через dig с резервом на dns_get_record.
 *
 * Зачем dig: нужно спрашивать КОНКРЕТНЫЙ сервер имён (ns1.fornex.com), иначе
 * проверка показывает кэш публичного резолвера, а не состояние зоны.
 * Замерено 30.10.2026: зона Fornex пересобирается минутами, а Resolve-DnsName
 * в PowerShell отдаёт старое значение по TTL — отсюда и требование к прямому
 * запросу к авторитетному серверу.
 *
 * @package FornexPanel
 */

declare(strict_types=1);

namespace FornexPanel\Service;

final class DnsResolver
{
    public function __construct(
        private readonly string $publicResolver = '8.8.8.8',
        private readonly string $authoritative = 'ns1.fornex.com',
    ) {
    }

    public function publicServer(): string
    {
        return $this->publicResolver;
    }

    public function authoritativeServer(): string
    {
        return $this->authoritative;
    }

    /**
     * @return array{values: list<string>, error: ?string}
     */
    public function query(string $type, string $name, ?string $server = null): array
    {
        $name = rtrim($name, '.');

        if ($name === '' || preg_match('/^[A-Za-z0-9._:-]+$/', $name) !== 1) {
            return ['values' => [], 'error' => 'недопустимое имя для запроса'];
        }

        if (!$this->hasDig()) {
            return $this->fallback($type, $name);
        }

        $command = sprintf(
            'dig +short +time=3 +tries=1 -t %s %s%s 2>&1',
            escapeshellarg($type),
            escapeshellarg($name),
            $server !== null ? ' @' . escapeshellarg($server) : '',
        );

        $output = [];
        $code = 0;
        @exec($command, $output, $code);

        $values = [];
        foreach ($output as $line) {
            $line = trim((string) $line);

            if ($line === '' || str_starts_with($line, ';')) {
                continue;
            }

            $values[] = $line;
        }

        return ['values' => $values, 'error' => null];
    }

    public function hasDig(): bool
    {
        static $has = null;

        if ($has === null) {
            // На Windows dig нет и быть не должно: там работает резервный резолвер,
            // а вызов "command -v" через cmd.exe только шумит в журнале.
            if (PHP_OS_FAMILY === 'Windows') {
                return $has = false;
            }

            $output = [];
            @exec('command -v dig 2>/dev/null', $output);
            $has = $output !== [];
        }

        return $has;
    }

    /** @return array{values: list<string>, error: ?string} */
    private function fallback(string $type, string $name): array
    {
        $map = [
            'A' => DNS_A,
            'AAAA' => DNS_AAAA,
            'MX' => DNS_MX,
            'TXT' => DNS_TXT,
            'NS' => DNS_NS,
            'PTR' => DNS_PTR,
            'CNAME' => DNS_CNAME,
        ];

        if (!isset($map[$type])) {
            return ['values' => [], 'error' => 'тип не поддержан резервным резолвером'];
        }

        $records = @dns_get_record($name, $map[$type]);

        if ($records === false) {
            return ['values' => [], 'error' => 'резолвер не ответил'];
        }

        $values = [];
        foreach ($records as $record) {
            $values[] = match ($type) {
                'MX' => ((int) ($record['prio'] ?? $record['pref'] ?? 0)) . ' ' . ($record['target'] ?? ''),
                'TXT' => '"' . ($record['txt'] ?? '') . '"',
                'NS' => $record['target'] ?? '',
                'PTR' => $record['target'] ?? '',
                'CNAME' => $record['target'] ?? '',
                default => $record['ip'] ?? $record['ipv6'] ?? '',
            };
        }

        return ['values' => array_values(array_filter($values)), 'error' => null];
    }
}
