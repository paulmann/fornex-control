<?php

/**
 * Fornex Control — front controller.
 *
 * Настройки (ключ API, пароль панели) лежат ВНЕ корня сайта — лучше всего
 * уровнем выше каталога приложения:
 *   <корень>/fornex-control/   — приложение
 *   <корень>/fornex.env        — настройки
 * Порядок поиска: FORNEX_ENV → <приложение>/.env → <корень>/fornex.env.
 * Если файла нет, используются настоящие переменные окружения (контейнер, CI);
 * без FORNEX_API_KEY панель дальше не пойдёт.
 *
 * @package FornexPanel
 */

declare(strict_types=1);

use FornexPanel\Kernel\Env;

// Для локального сервера PHP: статика отдаётся как есть, без прохода через роутер.
if (PHP_SAPI === 'cli-server') {
    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $path = is_string($path) ? rawurldecode($path) : '/';

    if ($path !== '/' && !str_contains($path, '..') && is_file(__DIR__ . $path)) {
        return false;
    }
}

$root = __DIR__;

require $root . '/backend/autoload.php';

$candidates = array_filter([
    getenv('FORNEX_ENV') ?: null,
    $root . '/.env',
    dirname($root) . '/fornex.env',
]);

$envFile = null;
foreach ($candidates as $candidate) {
    if (is_string($candidate) && is_file($candidate) && is_readable($candidate)) {
        $envFile = $candidate;
        break;
    }
}

if ($envFile !== null) {
    Env::load($envFile);
}

if (Env::get('FORNEX_API_KEY') === null) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Fornex Control: no configuration found.\n";
    echo "Expected ../fornex.env next to the application root, a path in FORNEX_ENV,\n";
    echo "or FORNEX_API_KEY in the environment (see .env.example).\n";

    exit;
}

/** @var \FornexPanel\Kernel\App $app */
$app = require $root . '/backend/bootstrap.php';
$app->run();
