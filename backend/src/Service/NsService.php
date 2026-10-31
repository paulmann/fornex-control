<?php

/**
 * NS домена: что стоит у регистратора, чем это подтверждается и как поменять.
 *
 * Важное различие, которое панель обязана показывать честно:
 *  - домен заказан У FORNEX → NS меняются через API (POST /dns/order/{id}/change_ns/);
 *  - домен у стороннего регистратора → API бессилен, NS ставятся в панели
 *    регистратора; панель выдаёт точный список и пошаговую инструкцию.
 *
 * @package FornexPanel
 */

declare(strict_types=1);

namespace FornexPanel\Service;

use FornexPanel\Fornex\Client;
use FornexPanel\Fornex\FornexException;
use FornexPanel\Kernel\HttpException;
use FornexPanel\Kernel\Logger;

final class NsService
{
    /** @var list<string> */
    private const DESIRED = ['ns1.fornex.com', 'ns2.fornex.com', 'ns3.fornex.com', 'ns4.fornex.com'];

    public function __construct(
        private readonly Client $client,
        private readonly ZoneService $zone,
        private readonly AuditService $audit,
        private readonly DnsResolver $resolver,
        private readonly Logger $logger,
    ) {
    }

    /** @return list<string> */
    public function desired(): array
    {
        return self::DESIRED;
    }

    /**
     * @return array<string, mixed>
     */
    public function status(string $domain): array
    {
        $parent = $this->resolver->query('NS', $domain, $this->resolver->publicServer());
        $parentList = array_values(array_map(
            static fn (string $ns): string => strtolower(rtrim($ns, '.')),
            $parent['values'],
        ));
        sort($parentList);

        $expected = self::DESIRED;
        sort($expected);

        $missing = array_values(array_diff($expected, $parentList));
        $extra = array_values(array_diff($parentList, $expected));

        $zoneNs = [];
        try {
            // NS и SOA отдаёт карточка домена; в entry_set их нет (они не редактируются).
            foreach ($this->client->domains() as $row) {
                if (strtolower((string) ($row['name'] ?? '')) !== $domain) {
                    continue;
                }

                foreach ((array) ($row['entry_set'] ?? []) as $entry) {
                    if (strtoupper((string) ($entry['type'] ?? '')) === 'NS') {
                        $zoneNs[] = strtolower(rtrim((string) ($entry['value'] ?? ''), '.'));
                    }
                }
            }
        } catch (FornexException $exception) {
            $this->logger->warning('NS зоны недоступны', ['domain' => $domain, 'status' => $exception->status]);
        }
        sort($zoneNs);

        $order = $this->findDomainOrder($domain);

        return [
            'domain' => $domain,
            'desired' => self::DESIRED,
            'parent' => $parentList,
            'zone_ns' => $zoneNs,
            'delegated' => $missing === [],
            'missing' => $missing,
            'extra' => $extra,
            'resolver' => $this->resolver->publicServer(),
            'order' => $order,
            'can_change_via_api' => $order !== null,
            'instruction' => $this->instruction($domain, $order !== null),
        ];
    }

    /**
     * Меняет NS у домена, заказанного у Fornex. Для чужого регистратора —
     * отказ с инструкцией, а не тихая попытка «что-нибудь сделать».
     *
     * @param list<string> $nameservers
     * @return array<string, mixed>
     */
    public function change(string $domain, array $nameservers, string $actor, bool $dryRun): array
    {
        $nameservers = array_values(array_unique(array_map(
            static fn (string $ns): string => strtolower(rtrim(trim($ns), '.')),
            $nameservers,
        )));

        foreach ($nameservers as $ns) {
            if (preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $ns) !== 1) {
                throw HttpException::badRequest('Некорректное имя NS: ' . $ns);
            }
        }

        if ($nameservers === []) {
            throw HttpException::badRequest('Список NS пуст.');
        }

        $order = $this->findDomainOrder($domain);

        if ($order === null) {
            throw HttpException::conflict(
                'Домен зарегистрирован не у Fornex: сменить NS через API нельзя. Поставьте NS в панели регистратора.',
                [
                    'instruction' => $this->instruction($domain, false),
                    'desired' => $nameservers,
                ],
            );
        }

        if ($dryRun) {
            $this->audit->record($actor, 'ns.change', $domain, 'сухой прогон: ' . implode(',', $nameservers), true, 'plan', [
                'order' => $order['id'],
                'nss' => $nameservers,
            ]);

            return ['dry_run' => true, 'order' => $order['id'], 'nss' => $nameservers];
        }

        $result = $this->client->changeNs((string) $order['id'], $nameservers);

        $this->audit->record($actor, 'ns.change', $domain, 'NS изменены: ' . implode(',', $nameservers), false, 'ok', [
            'order' => $order['id'],
            'nss' => $nameservers,
            'response' => $result,
        ]);

        return ['dry_run' => false, 'order' => $order['id'], 'result' => $result, 'nss' => $nameservers];
    }

    /** @return array<string, mixed>|null */
    public function findDomainOrder(string $domain): ?array
    {
        try {
            $orders = $this->client->orders();
        } catch (FornexException $exception) {
            $this->logger->warning('список заказов недоступен', ['status' => $exception->status]);

            return null;
        }

        foreach ($orders as $order) {
            $name = strtolower((string) ($order['name'] ?? ''));
            $type = strtolower((string) ($order['type'] ?? ''));

            if ($name === $domain && (str_contains($type, 'domain') || str_contains($type, 'домен'))) {
                return $order;
            }
        }

        return null;
    }

    /** Пошаговая инструкция для регистратора — текст, который можно скопировать. */
    public function instruction(string $domain, bool $atFornex): string
    {
        $list = implode("\n", self::DESIRED);

        if ($atFornex) {
            return "Домен {$domain} заказан у Fornex: панель может сменить NS сама кнопкой «Сменить NS через API».";
        }

        return <<<TEXT
Домен {$domain} зарегистрирован у стороннего регистратора. NS меняются там, в личном кабинете:

1. Откройте домен → раздел «Серверы имён» (NS / nameservers).
2. Укажите ровно эти четыре сервера:
{$list}
3. Сохраните и убедитесь, что в списке нет старых NS — иначе домен останется на прежней зоне.
4. Подождите обновления делегирования (обычно от 15 минут до нескольких часов, иногда до суток).

Для reg.ru: Домены → {$domain} → «Серверы имён» → «Изменить» → вписать по одному.
Для RU-Center: «Мои домены» → {$domain} → «DNS-серверы» → «Изменить».
TEXT;
    }
}
