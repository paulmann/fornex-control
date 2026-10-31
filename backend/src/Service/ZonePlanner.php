<?php

/**
 * Расчёт плана изменений зоны — без сети и без базы.
 *
 * Выделено отдельно, чтобы правило «не создавать дубль, а заменять» можно было
 * проверить тестом: ошибка здесь тихо ломает почту (две SPF дают permerror,
 * второй DKIM не совпадает с подписью), а увидеть это в интерфейсе нельзя.
 *
 * @package FornexPanel
 */

declare(strict_types=1);

namespace FornexPanel\Service;

use FornexPanel\Support\Str;

final class ZonePlanner
{
    /**
     * @param list<array{id: int, host: string, type: string, value: string, prio: ?int, ttl: ?int}> $existing
     * @param list<array<string, mixed>> $desired
     * @return array{create: list<array<string, mixed>>, update: list<array<string, mixed>>, skip: list<array<string, mixed>>, conflict: list<array<string, mixed>>}
     */
    public static function plan(array $existing, array $desired): array
    {
        $plan = ['create' => [], 'update' => [], 'skip' => [], 'conflict' => []];

        foreach ($desired as $wanted) {
            $host = Str::host($wanted['host'] ?? '');
            $type = Str::type($wanted['type'] ?? '');
            $value = Str::value($wanted['value'] ?? '');
            $prio = isset($wanted['prio']) && $wanted['prio'] !== null ? (int) $wanted['prio'] : null;
            $ttl = isset($wanted['ttl']) && $wanted['ttl'] !== null ? (int) $wanted['ttl'] : null;
            $why = (string) ($wanted['why'] ?? '');

            if ($type === '' || $value === '') {
                $plan['conflict'][] = [
                    'host' => $host,
                    'type' => $type,
                    'value' => Str::mask($value),
                    'reason' => 'не указан тип или значение записи',
                    'why' => $why,
                ];

                continue;
            }

            $item = [
                'host' => $host,
                'type' => $type,
                'value' => $value,
                'prio' => $prio,
                'ttl' => $ttl,
                'why' => $why,
            ];

            // «Одноимённая по смыслу» запись (SPF/DMARC/DKIM/подтверждение) не
            // заменяется слепо. SPF — объединяем механизмы, DMARC — не ослабляем
            // политику: иначе пресет срезал бы ip4: офиса или снял quarantine.
            $kind = self::semanticKind($type, $value);
            $twin = null;

            if ($kind !== null) {
                foreach ($existing as $row) {
                    if ($row['type'] === 'TXT' && $row['host'] === $host && self::semanticKind('TXT', $row['value']) === $kind) {
                        $twin = $row;

                        break;
                    }
                }
            }

            if ($twin !== null) {
                if ($kind === 'spf') {
                    $item['value'] = self::mergeSpf((string) $twin['value'], $value);
                }

                if ($kind === 'dmarc') {
                    $item['value'] = self::mergeDmarc((string) $twin['value'], $value);
                }

                // После слияния значение может совпасть с тем, что уже в зоне:
                // тогда это не правка, а «уже есть» — иначе шаг вечно «изменить 1».
                if (strcasecmp((string) $item['value'], (string) $twin['value']) === 0) {
                    $plan['skip'][] = $item + ['id' => $twin['id'], 'reason' => 'уже есть (с учётом слияния механизмов)'];

                    continue;
                }
            }

            $exact = null;
            $sameName = [];

            foreach ($existing as $row) {
                if ($row['host'] !== $host || $row['type'] !== $type) {
                    continue;
                }

                $sameName[] = $row;

                $valueMatches = $row['value'] === $value
                    || ($type === 'MX' && strcasecmp($row['value'], $value) === 0);

                $prioMatches = $type !== 'MX' || $row['prio'] === $prio;

                if ($valueMatches && $prioMatches) {
                    $exact = $row;

                    break;
                }
            }

            if ($exact !== null) {
                $plan['skip'][] = $item + ['id' => $exact['id'], 'reason' => 'запись уже есть'];

                continue;
            }

            // MX на ту же цель с другим приоритетом — это не создание, а правка.
            if ($type === 'MX') {
                foreach ($sameName as $row) {
                    if (strcasecmp($row['value'], $value) === 0) {
                        $plan['update'][] = $item + ['id' => $row['id'], 'current' => $row, 'reason' => 'та же цель, другой приоритет'];

                        continue 2;
                    }
                }
            }

            // SPF, DMARC, DKIM и подтверждение Google — «одноимённые по смыслу»:
            // запись с другим значением не добавляется второй.
            if ($twin !== null) {
                $plan['update'][] = $item + [
                    'id' => $twin['id'],
                    'current' => $twin,
                    'reason' => $kind === 'spf' ? 'SPF: добавятся недостающие механизмы' : 'замена значения того же назначения',
                ];

                continue;
            }

            $conflicts = self::conflicts($type, $host, $value, $existing);

            if ($conflicts !== []) {
                $plan['conflict'][] = $item + ['reason' => implode('; ', $conflicts)];

                continue;
            }

            $plan['create'][] = $item;
        }

        return $plan;
    }

    /**
     * Слияние SPF: механизмы нового значения идут первыми, недостающие из старого
     * добавляются следом. Так пресет никогда не выкинет ip4: чужого отправителя.
     */
    public static function mergeSpf(string $existing, string $wanted): string
    {
        $existing = trim(str_replace('"', '', $existing));
        $wanted = trim(str_replace('"', '', $wanted));

        if (!str_starts_with(strtolower($existing), 'v=spf1')) {
            return $wanted;
        }

        if (!str_starts_with(strtolower($wanted), 'v=spf1')) {
            return $existing;
        }

        [$wantedMech, $wantedAll] = self::spfParts($wanted);
        [$existingMech, $existingAll] = self::spfParts($existing);

        $mechanisms = $wantedMech;
        $known = array_map('strtolower', $wantedMech);

        foreach ($existingMech as $mechanism) {
            if (!in_array(strtolower($mechanism), $known, true)) {
                $mechanisms[] = $mechanism;
                $known[] = strtolower($mechanism);
            }
        }

        $all = $wantedAll ?? $existingAll;
        $value = 'v=spf1 ' . implode(' ', $mechanisms);

        return trim($value . ($all !== null ? ' ' . $all : ''));
    }

    /**
     * DMARC: политику не ослабляем. Если в зоне уже quarantine/reject, а пресет
     * предлагает none — остаётся строгая; в остальном берётся значение пресета.
     */
    public static function mergeDmarc(string $existing, string $wanted): string
    {
        $rank = ['none' => 0, 'quarantine' => 1, 'reject' => 2];

        $existingPolicy = preg_match('/\bp\s*=\s*(none|quarantine|reject)/i', $existing, $m) === 1 ? strtolower($m[1]) : null;
        $wantedPolicy = preg_match('/\bp\s*=\s*(none|quarantine|reject)/i', $wanted, $m2) === 1 ? strtolower($m2[1]) : null;

        if ($existingPolicy === null || $wantedPolicy === null) {
            return $wanted;
        }

        if (($rank[$existingPolicy] ?? 0) <= ($rank[$wantedPolicy] ?? 0)) {
            return $wanted;
        }

        return (string) preg_replace('/\bp\s*=\s*(none|quarantine|reject)/i', 'p=' . $existingPolicy, $wanted, 1);
    }

    /**
     * @return array{0: list<string>, 1: ?string}
     */
    private static function spfParts(string $value): array
    {
        $tokens = preg_split('/\s+/', trim($value)) ?: [];
        $mechanisms = [];
        $all = null;

        foreach (array_slice($tokens, 1) as $token) {
            if (preg_match('/^[+~?-]?all$/i', $token) === 1) {
                $all = $token;

                continue;
            }

            $mechanisms[] = $token;
        }

        return [$mechanisms, $all];
    }

    /** Назначение TXT-записи: два значения одного назначения не сосуществуют. */
    public static function semanticKind(string $type, string $value): ?string
    {
        if ($type !== 'TXT') {
            return null;
        }

        $clean = strtolower(ltrim(trim($value), '"'));

        return match (true) {
            str_starts_with($clean, 'v=spf1') => 'spf',
            str_starts_with($clean, 'v=dmarc1') => 'dmarc',
            str_starts_with($clean, 'v=dkim1') => 'dkim',
            str_starts_with($clean, 'google-site-verification=') => 'google-site-verification',
            default => null,
        };
    }

    /**
     * @param list<array<string, mixed>> $existing
     * @return list<string>
     */
    private static function conflicts(string $type, string $host, string $value, array $existing): array
    {
        $issues = [];

        foreach ($existing as $row) {
            if ($row['host'] !== $host) {
                continue;
            }

            if ($type === 'CNAME' && $row['type'] !== 'CNAME') {
                $issues[] = sprintf('на имени уже есть запись %s — CNAME должен быть единственным', $row['type']);
            }

            if ($type !== 'CNAME' && $row['type'] === 'CNAME') {
                $issues[] = 'на имени уже есть CNAME — другие записи с ним не сосуществуют';
            }

            if ($type === 'MX' && $row['type'] === 'MX' && $row['prio'] === null) {
                $issues[] = 'MX без приоритета уже существует — сначала приведите зону в порядок';
            }
        }

        return $issues;
    }
}
