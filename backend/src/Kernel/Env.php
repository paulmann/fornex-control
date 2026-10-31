<?php

/**
 * Чтение файла настроек формата KEY=VALUE.
 *
 * Поддерживает комментарии (#, ;), кавычки, пустые строки и значения с пробелами.
 * Уже заданные переменные окружения не перетираются: окружение важнее файла.
 *
 * @package FornexPanel
 */

declare(strict_types=1);

namespace FornexPanel\Kernel;

final class Env
{
    /** @var array<string, string> */
    private static array $values = [];

    public static function load(string $file): void
    {
        if (!is_readable($file)) {
            throw new \RuntimeException(sprintf('Файл настроек недоступен: %s', $file));
        }

        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, ';')) {
                continue;
            }

            $position = strpos($line, '=');
            if ($position === false) {
                continue;
            }

            $key = trim(substr($line, 0, $position));
            $value = trim(substr($line, $position + 1));

            if ($key === '') {
                continue;
            }

            // inline-комментарий только после пробела, чтобы не порезать значение
            if (preg_match('/^(.+?)\s+#.*$/', $value, $m) === 1) {
                $value = rtrim($m[1]);
            }

            if (strlen($value) > 1 && ($value[0] === '"' || $value[0] === "'") && str_ends_with($value, $value[0])) {
                $value = substr($value, 1, -1);
            }

            self::$values[$key] = $value;

            if (getenv($key) === false) {
                putenv($key . '=' . $value);
            }
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);

        if ($value === false || $value === '') {
            return self::$values[$key] ?? $default;
        }

        return $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);

        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key);

        return is_numeric($value) ? (int) $value : $default;
    }
}
