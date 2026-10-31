<?php

/**
 * Проверка домена: что реально отдают DNS и что лежит в зоне Fornex.
 *
 * Два источника намеренно разделены:
 *  - зона Fornex (API) — «как задумано»;
 *  - ответы DNS (авторитетные ns1.fornex.com и публичный резолвер) — «как есть».
 * Расхождение между ними и есть главный симптом: Fornex пересобирает зону
 * минутами, а публичные резолверы ещё и кэшируют ответ по TTL.
 *
 * @package FornexPanel
 */

declare(strict_types=1);

namespace FornexPanel\Service;

final class VerifyService
{
    public function __construct(
        private readonly ZoneService $zone,
        private readonly NsService $ns,
        private readonly DnsResolver $resolver,
    ) {
    }

    /**
     * @param array<string, mixed> $expect подсказки интерфейса: dkim_selector, mail_host
     * @return array<string, mixed>
     */
    public function check(string $domain, array $expect = []): array
    {
        $checks = [];

        $entries = [];
        $zoneError = null;

        try {
            $entries = $this->zone->entries($domain);
        } catch (\Throwable $exception) {
            $zoneError = $exception->getMessage();
        }

        $checks[] = $this->zoneCheck($entries, $zoneError);

        $parentNs = $this->resolver->query('NS', $domain, $this->resolver->publicServer());
        $checks[] = $this->nsCheck($parentNs, $domain);

        $mx = $this->resolver->query('MX', $domain, $this->resolver->authoritativeServer());
        $checks[] = $this->mxCheck($mx);

        $checks[] = $this->mxTargetsCheck($mx['values']);

        $txt = $this->resolver->query('TXT', $domain, $this->resolver->authoritativeServer());
        $checks[] = $this->spfCheck($txt['values']);

        $checks[] = $this->dkimCheck($domain, (string) ($expect['dkim_selector'] ?? 'google._domainkey'));

        $dmarc = $this->resolver->query('TXT', '_dmarc.' . $domain, $this->resolver->authoritativeServer());
        $checks[] = $this->dmarcCheck($dmarc['values']);

        $checks[] = $this->webCheck($domain);

        $checks[] = $this->ptrCheck($entries, $expect + ['domain' => $domain]);

        $checks[] = $this->lagCheck($entries, $mx['values'], $txt['values']);

        $summary = ['ok' => 0, 'warn' => 0, 'fail' => 0, 'skip' => 0];
        foreach ($checks as $check) {
            $summary[$check['status']] = ($summary[$check['status']] ?? 0) + 1;
        }

        return [
            'domain' => $domain,
            'checked_at' => gmdate('c'),
            'sources' => [
                'zone_api' => 'ru.fornex.com/api',
                'authoritative' => $this->resolver->authoritativeServer(),
                'public' => $this->resolver->publicServer(),
                'tool' => $this->resolver->hasDig() ? 'dig' : 'dns_get_record',
            ],
            'summary' => $summary,
            'checks' => $checks,
        ];
    }

    /** @param list<array<string, mixed>> $entries */
    private function zoneCheck(array $entries, ?string $error): array
    {
        if ($error !== null) {
            return $this->row('zone', 'Зона читается по API', 'fail', 'Не удалось прочитать зону: ' . $error);
        }

        $counts = [];
        foreach ($entries as $entry) {
            $counts[$entry['type']] = ($counts[$entry['type']] ?? 0) + 1;
        }

        $apex = array_values(array_filter($entries, static fn (array $e): bool => $e['type'] === 'A' && $e['host'] === ''));
        $status = $apex === [] ? 'warn' : 'ok';

        return $this->row(
            'zone',
            'Зона читается по API',
            $status,
            sprintf('записей %d; A в корне: %s', count($entries), $apex === [] ? 'нет' : $apex[0]['value']),
            ['counts' => $counts],
        );
    }

    /** @param array{values: list<string>, error: ?string} $answer */
    private function nsCheck(array $answer, string $domain): array
    {
        $got = array_map(static fn (string $ns): string => strtolower(rtrim($ns, '.')), $answer['values']);
        sort($got);

        $expected = $this->ns->desired();
        sort($expected);

        $missing = array_values(array_diff($expected, $got));

        if ($answer['error'] !== null) {
            return $this->row('ns', 'NS у регистратора', 'warn', 'DNS не ответил: ' . $answer['error'], ['values' => $got]);
        }

        if ($got === []) {
            return $this->row('ns', 'NS у регистратора', 'fail', 'NS не найдены — домен никуда не делегирован.', ['values' => []]);
        }

        $status = $missing === [] ? 'ok' : 'warn';
        $detail = $missing === []
            ? 'делегирование на Fornex полное: ' . implode(', ', $got)
            : 'не хватает: ' . implode(', ', $missing) . '; сейчас: ' . implode(', ', $got);

        return $this->row('ns', 'NS у регистратора', $status, $detail, ['values' => $got, 'missing' => $missing]);
    }

    /** @param array{values: list<string>, error: ?string} $answer */
    private function mxCheck(array $answer): array
    {
        if ($answer['error'] !== null) {
            return $this->row('mx', 'MX (входящая почта)', 'warn', 'DNS не ответил: ' . $answer['error']);
        }

        if ($answer['values'] === []) {
            return $this->row('mx', 'MX (входящая почта)', 'warn', 'MX нет: домен не принимает почту.');
        }

        return $this->row('mx', 'MX (входящая почта)', 'ok', implode(', ', $answer['values']), ['values' => $answer['values']]);
    }

    /** @param list<string> $values */
    private function mxTargetsCheck(array $values): array
    {
        if ($values === []) {
            return $this->row('mx_targets', 'Цели MX разрешаются', 'skip', 'MX нет — проверять нечего.');
        }

        $targets = [];
        foreach ($values as $row) {
            $parts = preg_split('/\s+/', trim($row)) ?: [];
            $targets[] = rtrim((string) end($parts), '.');
        }

        $broken = [];
        $resolved = [];

        foreach (array_unique($targets) as $target) {
            $a = $this->resolver->query('A', $target, $this->resolver->publicServer());
            $aaaa = $this->resolver->query('AAAA', $target, $this->resolver->publicServer());

            $addresses = array_merge($a['values'], $aaaa['values']);

            if ($addresses === []) {
                $broken[] = $target;
            } else {
                $resolved[$target] = $addresses;
            }
        }

        $status = $broken === [] ? 'ok' : 'fail';
        $detail = $broken === [] ? 'все цели MX имеют адрес' : 'не разрешаются: ' . implode(', ', $broken);

        return $this->row('mx_targets', 'Цели MX разрешаются', $status, $detail, ['resolved' => $resolved]);
    }

    /** @param list<string> $values */
    private function spfCheck(array $values): array
    {
        $spf = array_values(array_filter($values, static fn (string $v): bool => str_starts_with(strtolower(trim($v, '"')), 'v=spf1')));

        if ($spf === []) {
            return $this->row('spf', 'SPF', 'warn', 'SPF-записи нет: письма домена не подтверждены.');
        }

        if (count($spf) > 1) {
            return $this->row('spf', 'SPF', 'fail', 'SPF-записей ' . count($spf) . ': больше одной даёт permerror.', ['values' => $spf]);
        }

        $value = trim($spf[0], '"');
        $mechanism = 'нет завершающего механизма all';

        foreach (['-all', '~all', '?all', '+all'] as $candidate) {
            if (str_contains($value, $candidate)) {
                $mechanism = $candidate;
                break;
            }
        }

        $status = $mechanism === 'нет завершающего механизма all' ? 'warn' : 'ok';

        return $this->row('spf', 'SPF', $status, $mechanism . ' · ' . $value, ['value' => $value, 'all' => $mechanism]);
    }

    private function dkimCheck(string $domain, string $selector): array
    {
        $selectors = array_values(array_unique([$selector, 'google._domainkey', 'mail._domainkey', 'default._domainkey']));
        $found = [];

        foreach ($selectors as $candidate) {
            $answer = $this->resolver->query('TXT', $candidate . '.' . $domain, $this->resolver->authoritativeServer());

            foreach ($answer['values'] as $value) {
                $clean = trim($value, '"');

                if (str_contains($clean, 'v=DKIM1') || str_contains($clean, 'p=')) {
                    $found[$candidate] = [
                        'length' => strlen($clean),
                        'algorithm' => str_contains($clean, 'k=ed25519') ? 'ed25519' : 'rsa',
                    ];

                    break;
                }
            }
        }

        if ($found === []) {
            return $this->row('dkim', 'DKIM', 'warn', 'DKIM-записей нет: письма уходят без подписи домена.');
        }

        $parts = [];
        foreach ($found as $selector => $meta) {
            $parts[] = sprintf('%s (%s, %d симв.)', $selector, $meta['algorithm'], $meta['length']);
        }

        return $this->row('dkim', 'DKIM', 'ok', implode(', ', $parts), ['values' => $found]);
    }

    /** @param list<string> $values */
    private function dmarcCheck(array $values): array
    {
        $dmarc = array_values(array_filter($values, static fn (string $v): bool => str_contains(strtolower($v), 'v=dmarc1')));

        if ($dmarc === []) {
            return $this->row('dmarc', 'DMARC', 'warn', 'DMARC-записи нет: отчёты о подделках не приходят.');
        }

        $value = trim($dmarc[0], '"');
        $policy = preg_match('/p=([a-z]+)/i', $value, $m) === 1 ? $m[1] : 'не указана';

        return $this->row('dmarc', 'DMARC', 'ok', 'политика p=' . $policy, ['value' => $value]);
    }

    private function webCheck(string $domain): array
    {
        $apex = $this->resolver->query('A', $domain, $this->resolver->publicServer());
        $www = $this->resolver->query('A', 'www.' . $domain, $this->resolver->publicServer());

        if ($apex['values'] === [] && $www['values'] === []) {
            return $this->row('web', 'Сайт (A-записи)', 'warn', 'ни корень, ни www не имеют A-записи');
        }

        $detail = sprintf('A @: %s · A www: %s', $apex['values'] === [] ? '—' : implode(',', $apex['values']), $www['values'] === [] ? '—' : implode(',', $www['values']));

        return $this->row('web', 'Сайт (A-записи)', 'ok', $detail, ['apex' => $apex['values'], 'www' => $www['values']]);
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @param array<string, mixed> $expect
     */
    private function ptrCheck(array $entries, array $expect): array
    {
        $apex = array_values(array_filter($entries, static fn (array $e): bool => $e['type'] === 'A' && $e['host'] === ''));
        $mail = array_values(array_filter($entries, static fn (array $e): bool => $e['type'] === 'A' && $e['host'] === 'mail'));

        if ($mail === []) {
            return $this->row('ptr', 'PTR почтового IP', 'skip', 'A mail нет — проверять нечего.');
        }

        $ip = (string) $mail[0]['value'];

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return $this->row('ptr', 'PTR почтового IP', 'skip', 'A mail указывает не на IP, а на имя: ' . $ip);
        }

        $reverseName = $this->reverseName($ip);

        if ($reverseName === null) {
            return $this->row('ptr', 'PTR почтового IP', 'skip', 'для ' . $ip . ' обратная зона не строится (IPv6 пока не поддержан)');
        }

        $reverse = $this->reverseHost($this->resolver->query('PTR', $reverseName, $this->resolver->publicServer()));

        if ($reverse === null) {
            return $this->row('ptr', 'PTR почтового IP', 'warn', 'у ' . $ip . ' нет PTR — получатели сочтут отправителя подозрительным');
        }

        $host = strtolower(rtrim($reverse, '.'));

        $status = 'ok';
        $detail = $ip . ' → ' . $host;

        if ($host !== strtolower(rtrim((string) ($expect['mail_host'] ?? ''), '.'))) {
            $status = 'warn';
            $detail .= ' (ожидалось имя почтового хоста домена)';
        }

        return $this->row('ptr', 'PTR почтового IP', $status, $detail, ['ptr' => $host]);
    }

    /**
     * Сравнивает зону Fornex с тем, что уже отдают NS: расхождение = зона ещё
     * не пересобралась либо правку съел кэш.
     *
     * @param list<array<string, mixed>> $entries
     * @param list<string> $mxValues
     * @param list<string> $txtValues
     */
    private function lagCheck(array $entries, array $mxValues, array $txtValues): array
    {
        $zoneMx = [];
        foreach ($entries as $entry) {
            if ($entry['type'] === 'MX') {
                $zoneMx[] = strtolower(rtrim($entry['value'], '.')) . '#' . (int) $entry['prio'];
            }
        }
        sort($zoneMx);

        $dnsMx = [];
        $prioKnown = true;

        foreach ($mxValues as $row) {
            $parts = preg_split('/\s+/', trim($row)) ?: [];
            $prio = (int) ($parts[0] ?? 0);
            $target = strtolower(rtrim((string) ($parts[1] ?? ''), '.'));
            $dnsMx[] = $target . '#' . $prio;

            // Резервный резолвер на Windows не отдаёт приоритет MX вовсе:
            // тогда сравниваем только цели, чтобы не показывать ложное расхождение.
            if ($prio === 0) {
                $prioKnown = false;
            }
        }
        sort($dnsMx);

        if (!$prioKnown) {
            $zoneTargets = array_map(static fn (string $row): string => explode('#', $row)[0], $zoneMx);
            $dnsTargets = array_map(static fn (string $row): string => explode('#', $row)[0], $dnsMx);
            sort($zoneTargets);
            sort($dnsTargets);

            return $zoneTargets === $dnsTargets
                ? $this->row('lag', 'Зона и DNS согласованы', 'ok', 'цели MX в зоне и в DNS совпадают (приоритеты резолвер не отдал)')
                : $this->row('lag', 'Зона и DNS согласованы', 'warn', 'цели MX в зоне Fornex и в DNS различаются: зона пересобирается минутами.', ['zone' => $zoneTargets, 'dns' => $dnsTargets]);
        }

        if ($zoneMx === [] && $dnsMx === []) {
            return $this->row('lag', 'Зона и DNS согласованы', 'skip', 'MX нет ни в зоне, ни в DNS.');
        }

        if ($zoneMx !== $dnsMx) {
            return $this->row(
                'lag',
                'Зона и DNS согласованы',
                'warn',
                'MX в зоне Fornex и в DNS различаются: зона пересобирается минутами, повторите проверку позже.',
                ['zone' => $zoneMx, 'dns' => $dnsMx],
            );
        }

        $zoneSpf = [];
        foreach ($entries as $entry) {
            if ($entry['type'] === 'TXT' && $entry['host'] === '' && str_starts_with(strtolower($entry['value']), 'v=spf1')) {
                $zoneSpf[] = $entry['value'];
            }
        }

        $dnsSpf = [];
        foreach ($txtValues as $value) {
            $clean = trim($value, '"');
            if (str_starts_with(strtolower($clean), 'v=spf1')) {
                $dnsSpf[] = $clean;
            }
        }

        if ($zoneSpf !== [] && $dnsSpf === []) {
            return $this->row('lag', 'Зона и DNS согласованы', 'warn', 'SPF есть в зоне Fornex, но DNS его ещё не отдаёт — пересборка зоны не завершена.');
        }

        return $this->row('lag', 'Зона и DNS согласованы', 'ok', 'MX и SPF в зоне совпадают с ответами NS.');
    }

    /** Обратное имя для IPv4: 1.2.3.4 → 4.3.2.1.in-addr.arpa. */
    private function reverseName(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return null;
        }

        return implode('.', array_reverse(explode('.', $ip))) . '.in-addr.arpa';
    }

    private function reverseHost(array $answer): ?string
    {
        if ($answer['values'] === []) {
            return null;
        }

        return trim((string) $answer['values'][0], '"');
    }

    /** @param array<string, mixed> $values */
    private function row(string $id, string $title, string $status, string $detail, array $values = []): array
    {
        return [
            'id' => $id,
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
            'values' => $values,
        ];
    }
}
