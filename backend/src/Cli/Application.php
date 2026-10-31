<?php

/**
 * Командная строка панели: те же операции, что в интерфейсе.
 *
 * Правило безопасности: изменяющие команды по умолчанию работают как сухой
 * прогон и печатают план. Запись выполняется только с явным --apply.
 * Это то же правило, что и в панели (предпросмотр → применение), и оно
 * позволяет запускать команды из cron и из скриптов без риска «сделать сразу».
 *
 * @package FornexPanel
 */

declare(strict_types=1);

namespace FornexPanel\Cli;

use FornexPanel\Fornex\FornexException;
use FornexPanel\Kernel\HttpException;
use FornexPanel\Service\Services;
use FornexPanel\Support\Json;
use FornexPanel\Support\Str;
use Throwable;

final class Application
{
    /** @var array<string, string> */
    private array $options = [];

    /** @var list<string> */
    private array $arguments = [];

    public function __construct(private readonly Services $services)
    {
    }

    /**
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        $command = $argv[1] ?? 'help';
        $this->parse(array_slice($argv, 2));

        try {
            return match ($command) {
                'domains' => $this->domains(),
                'zone:show' => $this->zoneShow(),
                'zone:snapshot' => $this->zoneSnapshot(),
                'entry:add' => $this->entryAdd(),
                'entry:update' => $this->entryUpdate(),
                'entry:delete' => $this->entryDelete(),
                'preset:list' => $this->presetList(),
                'preset:apply' => $this->presetApply(),
                'verify' => $this->verify(),
                'ns:status' => $this->nsStatus(),
                'ns:apply' => $this->nsApply(),
                'domain:add' => $this->domainAdd(),
                'audit' => $this->audit(),
                'help', '--help', '-h' => $this->help(),
                default => $this->unknown($command),
            };
        } catch (HttpException $exception) {
            $this->fail($exception->getMessage(), $exception->status, $exception->details);

            return 1;
        } catch (FornexException $exception) {
            $this->fail($exception->getMessage(), $exception->status, $exception->toArray());

            return 1;
        } catch (Throwable $exception) {
            $this->fail($exception->getMessage(), 1, ['type' => $exception::class]);

            return 1;
        }
    }

    private function domains(): int
    {
        $rows = [];

        foreach ($this->services->client->domains() as $row) {
            $entries = is_array($row['entry_set'] ?? null) ? $row['entry_set'] : [];
            $counts = [];
            foreach ($entries as $entry) {
                $type = strtoupper((string) ($entry['type'] ?? ''));
                $counts[$type] = ($counts[$type] ?? 0) + 1;
            }
            ksort($counts);

            $rows[] = [
                'name' => (string) ($row['name'] ?? ''),
                'entries' => count($entries),
                'types' => $counts,
                'updated' => (string) ($row['updated'] ?? ''),
            ];
        }

        if ($this->has('json')) {
            $this->printJson(['domains' => $rows]);
        } else {
            printf("%-28s %6s  %s\n", 'домен', 'записей', 'по типам');
            foreach ($rows as $row) {
                $types = [];
                foreach ($row['types'] as $type => $count) {
                    $types[] = $type . '=' . $count;
                }
                printf("%-28s %6d  %s\n", $row['name'], $row['entries'], implode(' ', $types));
            }
        }

        return 0;
    }

    private function zoneShow(): int
    {
        $domain = $this->domainArg();
        $entries = $this->services->zone->entries($domain);

        if ($this->has('json')) {
            $this->printJson(['domain' => $domain, 'entries' => $entries]);

            return 0;
        }

        printf("домен %s, записей %d\n\n", $domain, count($entries));
        printf("%-8s %-6s %-22s %-5s %-6s %s\n", 'id', 'тип', 'имя', 'prio', 'ttl', 'значение');

        foreach ($entries as $entry) {
            printf(
                "%-8d %-6s %-22s %-5s %-6s %s\n",
                $entry['id'],
                $entry['type'],
                $entry['host'] === '' ? '@' : $entry['host'],
                $entry['prio'] === null ? '' : (string) $entry['prio'],
                $entry['ttl'] === null ? '' : (string) $entry['ttl'],
                Str::mask($entry['value'], 64),
            );
        }

        return 0;
    }

    private function zoneSnapshot(): int
    {
        $domain = $this->domainArg();
        $meta = $this->services->zone->snapshot($domain, 'cli', (string) ($this->options['note'] ?? ''));
        $this->services->audit->registerSnapshot($domain, 'cli', $meta);

        if ($this->has('json')) {
            $this->printJson($meta);

            return 0;
        }

        printf("снимок: %s (%d записей)\n", $meta['path'], $meta['entries']);

        return 0;
    }

    private function entryAdd(): int
    {
        $domain = $this->domainArg();
        $record = $this->recordFromOptions();

        $created = $this->services->zone->addEntry($domain, $record, 'cli');
        $this->printJson(['created' => $created, 'record' => $record]);

        return 0;
    }

    private function entryUpdate(): int
    {
        $domain = $this->domainArg();
        $id = (int) ($this->options['id'] ?? 0);
        $record = $this->recordFromOptions();

        $updated = $this->services->zone->updateEntry($domain, $id, $record, 'cli');
        $this->printJson(['updated' => $updated, 'id' => $id]);

        return 0;
    }

    private function entryDelete(): int
    {
        $domain = $this->domainArg();
        $id = (int) ($this->options['id'] ?? 0);

        if (!$this->has('apply')) {
            $current = $this->services->zone->find($domain, $id);

            if ($current === null) {
                $this->fail('запись с таким id не найдена', 404, ['id' => $id]);

                return 1;
            }

            $this->printJson(['dry_run' => true, 'would_delete' => $current, 'hint' => 'повторите с --apply']);

            return 0;
        }

        $this->services->zone->deleteEntry($domain, $id, 'cli');
        $this->printJson(['deleted' => $id]);

        return 0;
    }

    private function presetList(): int
    {
        $presets = $this->services->presets->all();

        if ($this->has('json')) {
            $this->printJson(['presets' => $presets]);

            return 0;
        }

        foreach ($presets as $preset) {
            printf("%-18s %s\n", $preset['id'], $preset['title']);
            printf("                   %s\n\n", $preset['summary']);
        }

        return 0;
    }

    private function presetApply(): int
    {
        $domain = $this->domainArg();
        $presetId = $this->arguments[1] ?? '';

        if ($presetId === '') {
            $this->fail('укажите пресет: preset:apply <домен> <пресет> [--param key=value]', 2);

            return 2;
        }

        $params = [];
        foreach ($this->options as $key => $value) {
            if (str_starts_with($key, 'param:')) {
                $params[substr($key, 6)] = $value;
            }
        }

        $built = $this->services->presets->build($presetId, $domain, $params);
        $plan = $this->services->zone->plan($domain, $built['records']);
        $apply = $this->has('apply');

        $result = $this->services->zone->applyPlan($domain, $plan, !$apply, 'cli', 'пресет ' . $presetId);

        if ($this->has('json')) {
            $this->printJson([
                'preset' => $built['id'],
                'domain' => $domain,
                'plan' => $plan,
                'result' => $result,
            ]);

            return $result['errors'] === [] ? 0 : 1;
        }

        printf("домен %s · пресет %s (%s)\n\n", $domain, $built['id'], $built['title']);

        foreach ($built['records'] as $record) {
            printf("  %-6s %-18s %s\n", $record['type'], $record['host'] === '' ? '@' : $record['host'], Str::mask((string) $record['value'], 60));
            if (($record['why'] ?? '') !== '') {
                printf("         %s\n", $record['why']);
            }
        }

        printf(
            "\nплан: создать %d, изменить %d, пропустить %d, конфликтов %d\n",
            count($plan['create']),
            count($plan['update']),
            count($plan['skip']),
            count($plan['conflict']),
        );

        foreach ($plan['conflict'] as $conflict) {
            printf("  конфликт: %s %s — %s\n", $conflict['type'], $conflict['host'] === '' ? '@' : $conflict['host'], $conflict['reason']);
        }

        foreach ($result['created'] as $created) {
            printf("  создано id %d: %s %s\n", $created['id'], $created['type'], $created['value']);
        }

        foreach ($result['updated'] as $updated) {
            printf("  изменено id %d: %s prio %s\n", $updated['id'], $updated['type'], (string) $updated['prio']);
        }

        foreach ($result['errors'] as $error) {
            printf("  ошибка: HTTP %s — %s\n", (string) ($error['status'] ?? '?'), (string) ($error['detail'] ?? ''));
        }

        if (!$apply) {
            print "\nсухой прогон: ничего не изменено. Для записи повторите с --apply\n";
        } else {
            printf("\nснимок до изменений: %s\n", (string) ($result['snapshot']['path'] ?? '—'));
        }

        return $result['errors'] === [] ? 0 : 1;
    }

    private function verify(): int
    {
        $domain = $this->domainArg();
        $report = $this->services->verify->check($domain, [
            'dkim_selector' => (string) ($this->options['dkim-selector'] ?? 'google._domainkey'),
            'mail_host' => (string) ($this->options['mail-host'] ?? 'mail.' . $domain),
        ]);

        if ($this->has('json')) {
            $this->printJson($report);

            return 0;
        }

        printf("проверка %s (%s)\n\n", $domain, $report['checked_at']);
        foreach ($report['checks'] as $check) {
            printf("[%s] %-26s %s\n", strtoupper($check['status']), $check['title'], $check['detail']);
        }

        $summary = $report['summary'];
        printf("\nитог: ок %d, предупреждений %d, ошибок %d, пропущено %d\n",
            $summary['ok'] ?? 0, $summary['warn'] ?? 0, $summary['fail'] ?? 0, $summary['skip'] ?? 0);

        return ($summary['fail'] ?? 0) > 0 ? 1 : 0;
    }

    private function nsStatus(): int
    {
        $domain = $this->domainArg();
        $status = $this->services->ns->status($domain);

        if ($this->has('json')) {
            $this->printJson($status);

            return 0;
        }

        printf("домен %s\n", $domain);
        printf("  NS у регистратора: %s\n", $status['parent'] === [] ? '—' : implode(', ', $status['parent']));
        printf("  NS в зоне Fornex: %s\n", $status['zone_ns'] === [] ? '—' : implode(', ', $status['zone_ns']));
        printf("  делегирование на Fornex: %s\n", $status['delegated'] ? 'полное' : 'неполное');
        printf("  смена через API: %s\n", $status['can_change_via_api'] ? 'доступна' : 'недоступна (домен у стороннего регистратора)');

        if (!$status['delegated']) {
            printf("\n%s\n", $status['instruction']);
        }

        return 0;
    }

    private function nsApply(): int
    {
        $domain = $this->domainArg();
        $raw = (string) ($this->options['ns'] ?? implode(',', $this->services->ns->desired()));
        $list = array_values(array_filter(array_map('trim', explode(',', $raw))));

        $result = $this->services->ns->change($domain, $list, 'cli', !$this->has('apply'));

        $this->printJson($result);

        return 0;
    }

    private function domainAdd(): int
    {
        $name = Str::domain($this->arguments[0] ?? '');

        if ($name === null) {
            $this->fail('укажите домен: domain:add <домен> [--ip 1.2.3.4] [--apply]', 2);

            return 2;
        }

        $ip = (string) ($this->options['ip'] ?? $this->services->config->string('fornex.default_ip'));

        if (!$this->has('apply')) {
            $this->printJson([
                'dry_run' => true,
                'would_create' => ['name' => $name, 'ip' => $ip],
                'warning' => 'у аккаунта может быть выключено право создавать зоны через API (проверяется на --apply)',
            ]);

            return 0;
        }

        try {
            $created = $this->services->client->createDomain($name, $ip);
        } catch (FornexException $exception) {
            $this->fail($exception->getMessage(), $exception->status, $exception->toArray());

            return 1;
        }

        $this->services->audit->record('cli', 'domain.add', $name, 'зона создана через CLI', false, 'ok', ['ip' => $ip]);
        $this->printJson(['created' => $created]);

        return 0;
    }

    private function audit(): int
    {
        $domain = (string) ($this->options['domain'] ?? '');
        $events = $this->services->audit->list((int) ($this->options['limit'] ?? 30), $domain);

        if ($this->has('json')) {
            $this->printJson(['events' => $events]);

            return 0;
        }

        foreach ($events as $event) {
            printf(
                "%s %-7s %-14s %-22s %s\n",
                $event['created_at'],
                $event['dry_run'] ? 'план' : $event['status'],
                (string) $event['action'],
                (string) $event['target'],
                Str::mask((string) $event['summary'], 80),
            );
        }

        return 0;
    }

    private function help(): int
    {
        echo <<<TEXT
Fornex Control — командная строка (изменения только с --apply)

  domains                          список зон аккаунта
  zone:show <домен>                записи зоны
  zone:snapshot <домен>            снимок зоны в var/snapshots
  entry:add <домен> --host --type --value [--prio] [--ttl]
  entry:update <домен> --id ...    правка записи по id
  entry:delete <домен> --id [--apply]
  preset:list                      что есть в config/presets.json
  preset:apply <домен> <пресет> [--param:key=value] [--apply]
  verify <домен>                   DNS и зона: NS, MX, SPF, DKIM, DMARC, PTR
  ns:status <домен>                делегирование и инструкция
  ns:apply <домен> [--ns a,b,c] [--apply]
  domain:add <домен> [--ip] [--apply]
  audit [--domain=...] [--limit=30]

Общие ключи: --json (машинный вывод), --apply (выполнить запись).

TEXT;

        return 0;
    }

    private function unknown(string $command): int
    {
        $this->fail('неизвестная команда: ' . $command, 2);

        return 2;
    }

    /** @param list<string> $args */
    private function parse(array $args): void
    {
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--')) {
                $pair = substr($arg, 2);

                if (str_contains($pair, '=')) {
                    [$key, $value] = explode('=', $pair, 2);
                    $this->options[$key] = $value;
                } else {
                    $this->options[$pair] = '1';
                }

                continue;
            }

            $this->arguments[] = $arg;
        }

        // --param key=value допускает форму двумя аргументами
        if (isset($this->options['param']) && $this->options['param'] !== '1') {
            $pair = $this->options['param'];
            if (str_contains($pair, '=')) {
                [$key, $value] = explode('=', $pair, 2);
                $this->options['param:' . $key] = $value;
            }
            unset($this->options['param']);
        }
    }

    private function has(string $key): bool
    {
        return isset($this->options[$key]);
    }

    private function domainArg(): string
    {
        $domain = Str::domain($this->arguments[0] ?? '');

        if ($domain === null) {
            throw HttpException::badRequest('Укажите домен первым аргументом.');
        }

        return $domain;
    }

    /** @return array<string, mixed> */
    private function recordFromOptions(): array
    {
        return [
            'host' => (string) ($this->options['host'] ?? ''),
            'type' => (string) ($this->options['type'] ?? ''),
            'value' => (string) ($this->options['value'] ?? ''),
            'prio' => $this->options['prio'] ?? null,
            'ttl' => $this->options['ttl'] ?? null,
        ];
    }

    /** @param array<string, mixed> $data */
    private function printJson(array $data): void
    {
        echo Json::encode($data), "\n";
    }

    /** @param array<string, mixed> $details */
    private function fail(string $message, int $code, array $details = []): void
    {
        $payload = ['ok' => false, 'error' => ['code' => $code, 'message' => $message]];

        if ($details !== []) {
            $payload['error']['details'] = $details;
        }

        if ($this->has('json')) {
            $this->printJson($payload);

            return;
        }

        fwrite(STDERR, 'ошибка: ' . $message . PHP_EOL);

        foreach ($details as $key => $value) {
            if (is_scalar($value)) {
                fwrite(STDERR, sprintf('  %s: %s%s', $key, (string) $value, PHP_EOL));
            }
        }
    }
}
