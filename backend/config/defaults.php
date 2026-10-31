<?php

/**
 * Значения по умолчанию; всё перекрывается переменными окружения (.env).
 *
 * @package FornexPanel
 */

declare(strict_types=1);

return [
    'app' => [
        'name' => 'Fornex Control',
        'env' => 'production',
        'debug' => false,
        'url' => 'http://localhost:8080/',
        // префикс подкаталога в URL; пусто — приложение в корне сайта
        'base' => '',
        'timezone' => 'UTC',
        'version' => '1.1.0',
    ],
    'db' => [
        'path' => 'var/fornex.sqlite',
        'busy_timeout' => 5000,
    ],
    'log' => [
        'path' => 'var/log/app.log',
        'level' => 'info',
        'rotate_bytes' => 5_242_880,
        'keep_files' => 5,
    ],
    'snapshot' => [
        'dir' => 'var/snapshots',
    ],
    'session' => [
        'name' => 'fnx_session',
        'idle' => 1800,
        'secure' => true,
    ],
    'auth' => [
        'login' => 'admin',
        'hash' => '',
        // Как в панели reg.ru: запрос с этого адреса входит без пароля.
        // Пусто — автовход выключен.
        'auto_login_ip' => '',
    ],
    'fornex' => [
        'base_url' => 'https://ru.fornex.com/api',
        'timeout' => 30,
        'retries' => 2,
        'user_agent' => 'fornex-control/1.1 (+https://github.com/paulmann/fornex-control)',
        'default_ip' => '',
    ],
    'verify' => [
        'resolver' => '8.8.8.8',
        'authoritative' => 'ns1.fornex.com',
    ],
    'security' => [
        'login_attempts' => 10,
        'login_window' => 900,
    ],
];
