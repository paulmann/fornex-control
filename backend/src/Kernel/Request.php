<?php

/**
 * HTTP-запрос: путь, метод, параметры, JSON-тело, заголовки.
 *
 * @package FornexPanel
 */

declare(strict_types=1);

namespace FornexPanel\Kernel;

final class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     * @param array<string, string> $cookies
     */
    private function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $body,
        public readonly array $headers,
        public readonly array $cookies,
        public readonly string $ip,
    ) {
    }

    public static function fromGlobals(): self
    {
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $path = is_string($path) ? rawurldecode($path) : '/';

        // Префикс подкаталога (/fornex) снимает App по настройке app.base:
        // вычислять его из SCRIPT_NAME нельзя — в dev-режиме там файловый путь.
        $path = '/' . ltrim($path === '' ? '/' : $path, '/');

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with((string) $key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr((string) $key, 5)));
                $headers[$name] = (string) $value;
            }
        }

        $rawBody = (string) file_get_contents('php://input');
        $body = [];

        if ($rawBody !== '') {
            $decoded = json_decode($rawBody, true);
            $body = is_array($decoded) ? $decoded : [];
        }

        if ($body === [] && ($_POST !== [])) {
            $body = $_POST;
        }

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            $path,
            $_GET,
            $body,
            $headers,
            $_COOKIE,
            self::clientIp(),
        );
    }

    /** @param array<string, mixed> $query */
    public function withPath(string $path, array $query = []): self
    {
        return new self($this->method, $path, $query, $this->body, $this->headers, $this->cookies, $this->ip);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->input($key, $default);

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->input($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->input($key, $default);

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    public function isWrite(): bool
    {
        return in_array($this->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }

    public function bearer(): ?string
    {
        $auth = $this->header('authorization');

        if ($auth !== null && preg_match('/^Bearer\s+(.+)$/i', $auth, $m) === 1) {
            return trim($m[1]);
        }

        return null;
    }

    private static function clientIp(): string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

        // За прокси доверяем только частным адресам источника (nginx на этом же хосте).
        $forwarded = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($forwarded !== '' && (str_starts_with($ip, '127.') || str_starts_with($ip, '::1'))) {
            $first = trim(explode(',', $forwarded)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }

        return $ip;
    }
}
