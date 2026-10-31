<?php

/**
 * Журнал действий панели: кто, что, над каким доменом, сухой прогон или нет.
 *
 * Журнал пишется ДО и ПОСЛЕ изменения: строка «план» и строка «сделано».
 * Значения длинных записей (DKIM) в журнал не кладутся целиком — только маска.
 *
 * @package FornexPanel
 */

declare(strict_types=1);

namespace FornexPanel\Service;

use FornexPanel\Database\Database;
use FornexPanel\Support\Json;

final class AuditService
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @param array<string, mixed> $payload */
    public function record(
        string $actor,
        string $action,
        string $target = '',
        string $summary = '',
        bool $dryRun = false,
        string $status = 'ok',
        array $payload = [],
    ): int {
        return $this->db->insert('audit_events', [
            'created_at' => gmdate('c'),
            'actor' => $actor,
            'action' => $action,
            'target' => $target,
            'summary' => $summary,
            'dry_run' => $dryRun ? 1 : 0,
            'status' => $status,
            'request_id' => '',
            'payload' => Json::encode($payload),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(int $limit = 100, string $domain = ''): array
    {
        $limit = max(1, min(500, $limit));

        if ($domain !== '') {
            return $this->db->all(
                'SELECT * FROM audit_events WHERE target = :domain ORDER BY id DESC LIMIT ' . $limit,
                ['domain' => $domain],
            );
        }

        return $this->db->all('SELECT * FROM audit_events ORDER BY id DESC LIMIT ' . $limit);
    }

    /** @return list<array<string, mixed>> */
    public function snapshots(string $domain = '', int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));

        if ($domain !== '') {
            return $this->db->all(
                'SELECT * FROM snapshots WHERE domain = :domain ORDER BY id DESC LIMIT ' . $limit,
                ['domain' => $domain],
            );
        }

        return $this->db->all('SELECT * FROM snapshots ORDER BY id DESC LIMIT ' . $limit);
    }

    /** @param array<string, mixed> $meta */
    public function registerSnapshot(string $domain, string $kind, array $meta, string $note = ''): int
    {
        return $this->db->insert('snapshots', [
            'created_at' => gmdate('c'),
            'domain' => $domain,
            'kind' => $kind,
            'path' => (string) ($meta['path'] ?? ''),
            'sha256' => (string) ($meta['sha256'] ?? ''),
            'entries' => (int) ($meta['entries'] ?? 0),
            'note' => $note,
        ]);
    }
}
