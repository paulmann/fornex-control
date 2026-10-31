<?php

/**
 * Строковые помощники для доменов и записей зоны.
 *
 * @package FornexPanel
 */

declare(strict_types=1);

namespace FornexPanel\Support;

final class Str
{
    public const RECORD_TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA'];

    /**
     * Приводит имя домена к каноническому виду и проверяет формат.
     * Возвращает null, если имя невалидно: «на всякий случай» угадывать нельзя,
     * потому что имя уходит в URL API.
     */
    public static function domain(?string $value): ?string
    {
        $value = strtolower(trim((string) $value));
        $value = rtrim($value, '.');

        if ($value === '' || strlen($value) > 253) {
            return null;
        }

        if (preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $value) !== 1) {
            return null;
        }

        return $value;
    }

    /** Хост записи: пустая строка и «@» означают корень зоны. */
    public static function host(?string $value): string
    {
        $value = trim((string) $value);

        return $value === '@' ? '' : $value;
    }

    /** Значение записи: пробелы по краям убираем, внутренние не трогаем. */
    public static function value(?string $value): string
    {
        return trim((string) $value);
    }

    public static function type(?string $value): string
    {
        $value = strtoupper(trim((string) $value));

        return in_array($value, self::RECORD_TYPES, true) ? $value : '';
    }

    /** Имя, пригодное для ссылки API: без пробелов и управляющих символов. */
    public static function printable(string $value): string
    {
        return preg_replace('/[^\x20-\x7E]/', '', $value) ?? '';
    }

    /** Короткая маска значения для журнала: длинные строки не раздувают отчёт. */
    public static function mask(string $value, int $keep = 48): string
    {
        if (strlen($value) <= $keep) {
            return $value;
        }

        return substr($value, 0, $keep) . '…';
    }
}
