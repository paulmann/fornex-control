<?php

/**
 * Токены для скриптов: то же API панели, но без браузерной сессии.
 *
 * Токен показывается один раз при создании и хранится в базе только хэшем.
 * Токен с write_access=0 умеет только читать — этого достаточно для проверок
 * и мониторинга, и это осознанное ограничение по умолчанию.
 *
 * @package FornexPanel
 */

declare(strict_types=1);

namespace FornexPanel\Service;

use FornexPanel\Database\Database;

final class TokenService
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array{id: int, token: string, name: string, write_access: bool}
     */
    public function create(string $name, bool $writeAccess): array
    {
        $name = trim($name);

        if ($name === '') {
            $name = 'токен ' . gmdate('Y-m-d H:i');
        }

        $plain = 'fnx_' . rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        $prefix = substr($plain, 0, 12);

        $id = $this->db->insert('api_tokens', [
            'created_at' => gmdate('c'),
            'name' => $name,
            'prefix' => $prefix,
            'hash' => hash('sha256', $plain),
            'write_access' => $writeAccess ? 1 : 0,
        ]);

        return ['id' => $id, 'token' => $plain, 'name' => $name, 'write_access' => $writeAccess];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function verify(string $token): ?array
    {
        if ($token === '') {
            return null;
        }

        $row = $this->db->one(
            'SELECT * FROM api_tokens WHERE hash = :hash AND revoked_at IS NULL',
            ['hash' => hash('sha256', $token)],
        );

        if ($row === null) {
            return null;
        }

        $this->db->exec(
            'UPDATE api_tokens SET last_used_at = :now WHERE id = :id',
            ['now' => gmdate('c'), 'id' => $row['id']],
        );

        return $row;
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        return $this->db->all(
            'SELECT id, created_at, name, prefix, write_access, last_used_at, revoked_at
             FROM api_tokens ORDER BY id DESC',
        );
    }

    public function revoke(int $id): bool
    {
        return $this->db->exec(
            'UPDATE api_tokens SET revoked_at = :now WHERE id = :id AND revoked_at IS NULL',
            ['now' => gmdate('c'), 'id' => $id],
        ) > 0;
    }
}
