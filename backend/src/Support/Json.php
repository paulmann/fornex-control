<?php

/**
 * Кодирование JSON с кириллицей без экранирования.
 *
 * @package FornexPanel
 */

declare(strict_types=1);

namespace FornexPanel\Support;

final class Json
{
    /** @param mixed $value */
    public static function encode($value): string
    {
        return json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Читает JSON-файл; при ошибке возвращает null, чтобы вызывающий код
     * сам решил, падать или использовать значения по умолчанию.
     *
     * @return array<string, mixed>|null
     */
    public static function readFile(string $path): ?array
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }
}
