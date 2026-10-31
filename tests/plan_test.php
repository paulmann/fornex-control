<?php

/**
 * Тест правил планирования зоны — без сети и без базы.
 *
 * Запуск: php fornex/tests/plan_test.php  (код 0 — все проверки прошли)
 *
 * Проверяются ровно те правила, ошибка в которых ломает почту молча:
 * повторный пресет не должен копить дубли SPF/DMARC/DKIM, а MX на ту же цель
 * с другим приоритетом должен правиться, а не создаваться второй раз.
 */

declare(strict_types=1);

require __DIR__ . '/../backend/autoload.php';

use FornexPanel\Service\PresetService;
use FornexPanel\Service\ZonePlanner;
use FornexPanel\Support\Str;

$failures = 0;
$checks = 0;

function check(string $title, bool $ok, string $detail = ''): void
{
    global $failures, $checks;
    $checks++;

    if ($ok) {
        echo "  [ok]   {$title}\n";

        return;
    }

    $failures++;
    echo "  [FAIL] {$title}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

/** Превращает план в набор записей «как будто уже в зоне». */
function asExisting(array $records): array
{
    $rows = [];
    $id = 1000;

    foreach ($records as $record) {
        $rows[] = [
            'id' => ++$id,
            'host' => Str::host((string) $record['host']),
            'type' => Str::type((string) $record['type']),
            'value' => (string) $record['value'],
            'prio' => isset($record['prio']) && $record['prio'] !== null ? (int) $record['prio'] : null,
            'ttl' => isset($record['ttl']) && $record['ttl'] !== null ? (int) $record['ttl'] : null,
        ];
    }

    return $rows;
}

$presets = new PresetService(__DIR__ . '/../backend/config/presets.json', '203.0.113.10');

echo "1. Пресет Google на пустой зоне\n";
$google = $presets->build('google-workspace', 'example.com', []);
$plan = ZonePlanner::plan([], $google['records']);
check('создаётся 5 записей (MX 1, MX 5, MX 10, SPF, DMARC)', count($plan['create']) === 5, 'создано ' . count($plan['create']));
check('DKIM и подтверждение пропущены без параметров', count($plan['create']) === 5 && count($plan['update']) === 0);
check('вторая MX — абсолютное имя с точкой', $plan['create'][1]['value'] === 'SMTP.GOOGLE.COM.');
check('SPF собран из пресета', str_contains($plan['create'][2]['value'], 'include:_spf.google.com'));

echo "\n2. Повторный запуск на уже прописанной зоне (идемпотентность)\n";
$existing = asExisting($google['records']);
$plan = ZonePlanner::plan($existing, $google['records']);
check('ничего не создаётся', count($plan['create']) === 0, 'создано ' . count($plan['create']));
check('все 5 записей опознаны как существующие', count($plan['skip']) === 5, 'пропущено ' . count($plan['skip']));

echo "\n3. Сменился DKIM-ключ и строка подтверждения\n";
$google2 = $presets->build('google-workspace', 'example.com', [
    'dkim' => 'v=DKIM1; k=rsa; p=NEWKEY',
    'verification' => 'google-site-verification=NEWTOKEN',
]);
$existing = asExisting($presets->build('google-workspace', 'example.com', [
    'dkim' => 'v=DKIM1; k=rsa; p=OLDKEY',
    'verification' => 'google-site-verification=OLDTOKEN',
])['records']);
$plan = ZonePlanner::plan($existing, $google2['records']);
check('DKIM и подтверждение — замена, а не дубли', count($plan['update']) === 2 && count($plan['create']) === 0, sprintf('update %d, create %d', count($plan['update']), count($plan['create'])));

echo "\n4. MX на ту же цель с другим приоритетом\n";
$desired = [['host' => '@', 'type' => 'MX', 'value' => 'SMTP.GOOGLE.COM', 'prio' => 1, 'ttl' => 300]];
$existing = [[
    'id' => 7, 'host' => '', 'type' => 'MX', 'value' => 'SMTP.GOOGLE.COM', 'prio' => 10, 'ttl' => 300,
]];
$plan = ZonePlanner::plan($existing, $desired);
check('правится существующая запись id 7', count($plan['update']) === 1 && $plan['update'][0]['id'] === 7);
check('вторая MX не создаётся', count($plan['create']) === 0);

echo "\n5. Конфликты, которые нельзя создавать молча\n";
$existing = [['id' => 3, 'host' => 'www', 'type' => 'A', 'value' => '203.0.113.10', 'prio' => null, 'ttl' => 300]];
$plan = ZonePlanner::plan($existing, [['host' => 'www', 'type' => 'CNAME', 'value' => 'example.com', 'ttl' => 300]]);
check('CNAME рядом с A — конфликт', count($plan['conflict']) === 1 && count($plan['create']) === 0);

$existing = [
    ['id' => 4, 'host' => '', 'type' => 'TXT', 'value' => 'v=spf1 include:_spf.google.com ~all', 'prio' => null, 'ttl' => 300],
    ['id' => 5, 'host' => '', 'type' => 'TXT', 'value' => 'v=spf1 include:mail.ru -all', 'prio' => null, 'ttl' => 300],
];
$plan = ZonePlanner::plan($existing, [['host' => '@', 'type' => 'TXT', 'value' => 'v=spf1 mx -all', 'ttl' => 300]]);
check('третий SPF не добавляется', count($plan['create']) === 0);

echo "\n6. Проверка значений\n";
check('домен с двумя точками отвергается', Str::domain('bad..example.com') === null);
check('домен в верхнем регистре приводится к нижнему', Str::domain('Example.COM') === 'example.com');
check('SOA не считается редактируемым типом', Str::type('SOA') === '');
check('MX распознаётся как тип', Str::type('mx') === 'MX');
check('пустой хост и @ равнозначны', Str::host('@') === '' && Str::host('') === '');

echo "\n7. Пресет «сайт на VPS» с wildcard\n";
$site = $presets->build('site-on-vps', 'example.com', ['web_ip' => '203.0.113.7', 'wildcard' => 'да']);
check('три записи, включая *', count($site['records']) === 3, 'записей ' . count($site['records']));
check('адрес подставлен', $site['records'][0]['value'] === '203.0.113.7');

$site = $presets->build('site-on-vps', 'example.com', ['web_ip' => '203.0.113.7', 'wildcard' => 'нет']);
check('без wildcard — две записи', count($site['records']) === 2);

echo "\n8. Шаги Google: порядок и статусы\n";
$preset = new PresetService(__DIR__ . '/../backend/config/presets.json', '203.0.113.10');

$empty = $preset->stepsOverview('google-workspace', 'example.com', [], []);
$byId = [];
foreach ($empty['steps'] as $step) { $byId[$step['id']] = $step; }

check('четыре шага в нужном порядке', array_keys($byId) === ['verify', 'mail', 'dkim', 'dmarc'], implode(',', array_keys($byId)));
check('шаг 1 без строки — «нет данных»', $byId['verify']['status'] === 'empty', $byId['verify']['status']);
check('шаг 3 заблокирован без шага 1', $byId['dkim']['status'] === 'blocked', $byId['dkim']['status']);
check('шаг 2 создаёт 4 записи (три MX и SPF)', $byId['mail']['counts']['create'] === 4, (string) $byId['mail']['counts']['create']);

$withVerify = [[
    'id' => 1, 'host' => '', 'type' => 'TXT',
    'value' => 'google-site-verification=abc123', 'prio' => null, 'ttl' => 300,
]];
$afterVerify = $preset->stepsOverview('google-workspace', 'example.com', [], $withVerify);
$byId = [];
foreach ($afterVerify['steps'] as $step) { $byId[$step['id']] = $step; }
check('шаг 1 выполнен по записи в зоне', $byId['verify']['status'] === 'done' && $byId['verify']['present'] === true);
check('шаг 3 больше не заблокирован', $byId['dkim']['status'] !== 'blocked', $byId['dkim']['status']);

$withKey = $preset->stepsOverview('google-workspace', 'example.com', ['dkim' => 'v=DKIM1; k=rsa; p=KEY'], $withVerify);
$byId = [];
foreach ($withKey['steps'] as $step) { $byId[$step['id']] = $step; }
check('шаг 3 с ключом готов к записи', $byId['dkim']['status'] === 'todo' && $byId['dkim']['counts']['create'] === 1, $byId['dkim']['status']);

echo "\n9. Применение одного шага не тащит остальные\n";
$onlyVerify = $preset->build('google-workspace', 'example.com', ['verification' => 'google-site-verification=abc'], 'verify');
check('в шаге подтверждения ровно одна запись', count($onlyVerify['records']) === 1);
check('это TXT в корне', $onlyVerify['records'][0]['type'] === 'TXT' && $onlyVerify['records'][0]['host'] === '');

$onlyMail = $preset->build('google-workspace', 'example.com', [], 'mail');
check('в шаге почты четыре записи', count($onlyMail['records']) === 4, (string) count($onlyMail['records']));

$noKey = null;
try {
    $preset->build('google-workspace', 'example.com', [], 'dkim');
} catch (\FornexPanel\Kernel\HttpException $exception) {
    $noKey = $exception->getMessage();
}
check('шаг DKIM без ключа отказывает с объяснением', is_string($noKey) && str_contains($noKey, 'DKIM'), (string) $noKey);

echo "\n10. Поля шагов: подтверждение не всплывает на шаге MX\n";
$scoped = $preset->stepsOverview('google-workspace', 'example.com', [], []);
$fieldsById = [];
foreach ($scoped['steps'] as $step) { $fieldsById[$step['id']] = array_column($step['fields'], 'key'); }

check('шаг 1 — только строка подтверждения', $fieldsById['verify'] === ['verification'], implode(',', $fieldsById['verify']));
check('шаг 2 (MX/SPF) — без полей', $fieldsById['mail'] === [], implode(',', $fieldsById['mail']));
check('шаг 3 — только DKIM', $fieldsById['dkim'] === ['dkim'], implode(',', $fieldsById['dkim']));
check('шаг 4 — только DMARC', $fieldsById['dmarc'] === ['dmarc'], implode(',', $fieldsById['dmarc']));

$flat = $preset->stepsOverview('site-on-vps', 'example.com', [], []);
$flatFields = array_column($flat['steps'][0]['fields'], 'key');
check('у плоского пресета поля не теряются', $flatFields === ['web_ip', 'wildcard'], implode(',', $flatFields));

echo "\n11. Слияние SPF и DMARC: чужие механизмы не теряются\n";
$spfExisting = [[
    'id' => 20, 'host' => '', 'type' => 'TXT',
    'value' => 'v=spf1 include:_spf.google.com a mx ip4:203.0.113.20 ~all',
    'prio' => null, 'ttl' => 300,
]];
$spfDesired = [['host' => '', 'type' => 'TXT', 'value' => 'v=spf1 include:_spf.google.com ~all', 'ttl' => 300]];
$spfPlan = ZonePlanner::plan($spfExisting, $spfDesired);

check('SPF с уже полным набором — «уже есть»', count($spfPlan['skip']) === 1 && count($spfPlan['update']) === 0);

$merged = ZonePlanner::mergeSpf('v=spf1 include:_spf.google.com a mx ip4:203.0.113.20 ~all', 'v=spf1 include:_spf.google.com -all');
check('слияние сохраняет сторонний ip4', str_contains($merged, 'ip4:203.0.113.20'), $merged);
check('слияние сохраняет a и mx', str_contains($merged, ' a ') && str_contains($merged, ' mx '), $merged);
check('завершающий механизм берётся из пресета', str_ends_with($merged, '-all'), $merged);
check('дублей механизмов нет', substr_count($merged, 'include:_spf.google.com') === 1, $merged);

$dmarcPlan = ZonePlanner::plan(
    [['id' => 21, 'host' => '_dmarc', 'type' => 'TXT', 'value' => 'v=DMARC1; p=quarantine', 'prio' => null, 'ttl' => 3600]],
    [['host' => '_dmarc', 'type' => 'TXT', 'value' => 'v=DMARC1; p=none; rua=mailto:x@example.com', 'ttl' => 3600]],
);
check('политика DMARC не ослабляется', count($dmarcPlan['update']) === 1 && str_contains($dmarcPlan['update'][0]['value'], 'p=quarantine'), $dmarcPlan['update'][0]['value'] ?? '');
check('отчёты DMARC при этом добавляются', str_contains($dmarcPlan['update'][0]['value'], 'rua='), $dmarcPlan['update'][0]['value'] ?? '');

echo "\n12. DKIM: значение из консоли приводится к валидному виду\n";
$dkimCases = [
    'p=MIIBIjANBgkq' => 'v=DKIM1; k=rsa; p=MIIBIjANBgkq',
    'MIIBIjANBgkq+/=' => 'v=DKIM1; k=rsa; p=MIIBIjANBgkq+/=',
    "v=DKIM1; k=rsa;\np=MIIB\n AAAA" => 'v=DKIM1; k=rsa; p=MIIBAAAA',
    'v=DKIM1; h=sha256; k=rsa; p=ABC' => 'v=DKIM1; h=sha256; k=rsa; p=ABC',
];

foreach ($dkimCases as $given => $expected) {
    $built = $preset->build('google-workspace', 'example.com', ['dkim' => $given], 'dkim');
    $actual = $built['records'][0]['value'] ?? '';
    check('DKIM «' . substr(str_replace("\n", ' ', $given), 0, 22) . '…»', $actual === $expected, $actual);
}

echo "\n13. Подтверждение именем — только у шагов 1 и 3\n";
check('шаг 1 требует подтверждения', $preset->requiresConfirm('google-workspace', 'verify') === true);
check('шаг 2 (MX/SPF) — без подтверждения', $preset->requiresConfirm('google-workspace', 'mail') === false);
check('шаг 3 (DKIM) требует подтверждения', $preset->requiresConfirm('google-workspace', 'dkim') === true);
check('шаг 4 (DMARC) — без подтверждения', $preset->requiresConfirm('google-workspace', 'dmarc') === false);

$confirmOverview = $preset->stepsOverview('google-workspace', 'example.com', [], []);
$byId = [];
foreach ($confirmOverview['steps'] as $step) { $byId[$step['id']] = $step['confirm']; }
check('состояние подтверждения видно интерфейсу', $byId === ['verify' => true, 'mail' => false, 'dkim' => true, 'dmarc' => false], json_encode($byId));

check('плоский пресет по умолчанию требует подтверждения', $preset->requiresConfirm('site-on-vps', 'main') === true);

echo "\n";
if ($failures > 0) {
    echo "ПРОВАЛЕНО проверок: {$failures} из {$checks}\n";
    exit(1);
}

echo "все проверки пройдены: {$checks}\n";
exit(0);
