<?php

/**
 * Неизменяемая конфигурация с точечной адресацией: $config->get('fornex.timeout').
 *
 * Значения берутся из defaults.php и перекрываются переменными окружения
 * по карте ENV_MAP — так один и тот же код работает и в HTTP, и в CLI.
 *
 * @package FornexPanel
 */

declare(strict_types=1);

namespace FornexPanel\Kernel;

final class Config
{
    /** @var array<string, string> */
    private const ENV_MAP = [
        'APP_NAME' => 'app.name',
        'APP_ENV' => 'app.env',
        'APP_DEBUG' => 'app.debug',
        'APP_URL' => 'app.url',
        'APP_BASE' => 'app.base',
        'APP_TIMEZONE' => 'app.timezone',
        'APP_VERSION' => 'app.version',
        'DB_PATH' => 'db.path',
        'LOG_PATH' => 'log.path',
        'LOG_LEVEL' => 'log.level',
        'SNAPSHOT_DIR' => 'snapshot.dir',
        'SESSION_NAME' => 'session.name',
        'SESSION_IDLE' => 'session.idle',
        'SESSION_SECURE' => 'session.secure',
        'ADMIN_LOGIN' => 'auth.login',
        'ADMIN_PASSWORD_HASH' => 'auth.hash',
        'AUTO_LOGIN_IP' => 'auth.auto_login_ip',
        'FORNEX_BASE_URL' => 'fornex.base_url',
        'FORNEX_TIMEOUT' => 'fornex.timeout',
        'FORNEX_RETRIES' => 'fornex.retries',
        'FORNEX_USER_AGENT' => 'fornex.user_agent',
        'FORNEX_DEFAULT_IP' => 'fornex.default_ip',
        'VERIFY_RESOLVER' => 'verify.resolver',
        'VERIFY_AUTHORITATIVE' => 'verify.authoritative',
    ];

    /** @var array<string, 'bool'|'int'> */
    private const CASTS = [
        'app.debug' => 'bool',
        'session.secure' => 'bool',
        'session.idle' => 'int',
        'fornex.timeout' => 'int',
        'fornex.retries' => 'int',
        'log.rotate_bytes' => 'int',
        'log.keep_files' => 'int',
        'security.login_attempts' => 'int',
        'security.login_window' => 'int',
        'db.busy_timeout' => 'int',
    ];

    /** @param array<string, mixed> $values */
    private function __construct(private readonly array $values)
    {
    }

    /**
     * @param array<string, mixed> $defaults
     */
    public static function fromEnvironment(array $defaults): self
    {
        $values = $defaults;

        foreach (self::ENV_MAP as $env => $key) {
            $raw = Env::get($env);

            if ($raw === null || $raw === '') {
                continue;
            }

            $values = self::setDot($values, $key, self::cast($key, $raw));
        }

        return new self($values);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return self::getDot($this->values, $key) ?? $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->get($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->get($key);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);

        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    /** Абсолютный путь для ключа-пути: относительные считаются от корня приложения. */
    public function path(string $key): string
    {
        $value = $this->string($key);

        if ($value === '') {
            throw new \InvalidArgumentException(sprintf('Путь в настройке «%s» не задан.', $key));
        }

        if (str_starts_with($value, '/') || preg_match('~^[A-Za-z]:[\\\\/]~', $value) === 1) {
            return $value;
        }

        return APP_ROOT . '/' . ltrim($value, '/');
    }

    public function isProduction(): bool
    {
        return $this->string('app.env', 'production') === 'production';
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->values;
    }

    private static function cast(string $key, string $raw): mixed
    {
        return match (self::CASTS[$key] ?? null) {
            'bool' => filter_var($raw, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false,
            'int' => (int) $raw,
            default => $raw,
        };
    }

    /** @param array<string, mixed> $values */
    private static function setDot(array $values, string $key, mixed $value): array
    {
        $cursor = &$values;

        foreach (explode('.', $key) as $part) {
            if (!is_array($cursor)) {
                $cursor = [];
            }

            $cursor = &$cursor[$part];
        }

        $cursor = $value;

        return $values;
    }

    /** @param array<string, mixed> $values */
    private static function getDot(array $values, string $key): mixed
    {
        $cursor = $values;

        foreach (explode('.', $key) as $part) {
            if (!is_array($cursor) || !array_key_exists($part, $cursor)) {
                return null;
            }

            $cursor = $cursor[$part];
        }

        return $cursor;
    }
}
