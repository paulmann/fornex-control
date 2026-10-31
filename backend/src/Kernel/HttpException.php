<?php

/**
 * Исключение с HTTP-статусом и машинным кодом.
 *
 * @package FornexPanel
 */

declare(strict_types=1);

namespace FornexPanel\Kernel;

use RuntimeException;
use Throwable;

final class HttpException extends RuntimeException
{
    /**
     * @param array<string, mixed> $details
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $codeName,
        string $message,
        public readonly array $details = [],
        public readonly array $headers = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function notFound(string $message = 'Не найдено.'): self
    {
        return new self(404, 'not_found', $message);
    }

    public static function badRequest(string $message, array $details = []): self
    {
        return new self(422, 'invalid_request', $message, $details);
    }

    public static function forbidden(string $message = 'Действие запрещено.'): self
    {
        return new self(403, 'forbidden', $message);
    }

    public static function unauthorized(string $message = 'Нужен вход в панель.'): self
    {
        return new self(401, 'unauthorized', $message);
    }

    public static function conflict(string $message, array $details = []): self
    {
        return new self(409, 'conflict', $message, $details);
    }

    public static function upstream(string $message, array $details = []): self
    {
        return new self(502, 'upstream_error', $message, $details);
    }
}
