<?php

/**
 * Ошибка обращения к API Fornex.
 *
 * Хранит HTTP-статус и текст ответа, но НИКОГДА не хранит ключ: он живёт
 * только в заголовке запроса и в сообщения не попадает.
 *
 * @package FornexPanel
 */

declare(strict_types=1);

namespace FornexPanel\Fornex;

use RuntimeException;

final class FornexException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $detail,
        public readonly string $method,
        public readonly string $path,
    ) {
        parent::__construct(sprintf('Fornex API %s %s → HTTP %d: %s', $method, $path, $status, $detail));
    }

    /** Право создавать зоны через API у аккаунта выключено (проверено 30.10.2026). */
    public function isZoneCreateForbidden(): bool
    {
        return $this->status === 403 && $this->method === 'POST' && rtrim($this->path, '/') === '/dns/domain';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'detail' => $this->detail,
            'method' => $this->method,
            'path' => $this->path,
            'zone_create_forbidden' => $this->isZoneCreateForbidden(),
        ];
    }
}
