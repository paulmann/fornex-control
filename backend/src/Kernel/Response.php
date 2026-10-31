<?php

/**
 * Неизменяемый HTTP-ответ.
 *
 * @package FornexPanel
 */

declare(strict_types=1);

namespace FornexPanel\Kernel;

use FornexPanel\Support\Json;

final class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int $status = 200,
        public readonly string $body = '',
        public readonly array $headers = [],
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function ok(array $payload = [], int $status = 200): self
    {
        return self::json(['ok' => true] + $payload, $status);
    }

    /** @param array<string, mixed> $data */
    public static function json(array $data, int $status = 200): self
    {
        return new self($status, Json::encode($data), [
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    public static function error(string $code, string $message, int $status = 400, array $details = []): self
    {
        $payload = ['ok' => false, 'error' => ['code' => $code, 'message' => $message]];

        if ($details !== []) {
            $payload['error']['details'] = $details;
        }

        return self::json($payload, $status);
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($status, $html, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function text(string $text, int $status = 200): self
    {
        return new self($status, $text, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public static function redirect(string $location, int $status = 302): self
    {
        return new self($status, '', ['Location' => $location]);
    }

    public static function noContent(): self
    {
        return new self(204, '');
    }

    public function withHeader(string $name, string $value): self
    {
        return new self($this->status, $this->body, $this->headers + [$name => $value]);
    }

    public function send(): void
    {
        if (headers_sent()) {
            echo $this->body;

            return;
        }

        http_response_code($this->status);

        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value, true);
        }

        echo $this->body;
    }
}
