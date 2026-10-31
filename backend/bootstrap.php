<?php

/**
 * Сборка приложения: настройки → база → сервисы → маршруты.
 *
 * Один вход для HTTP и CLI (bin/fornex), поэтому панель и скрипт всегда
 * работают с одинаковой конфигурацией.
 *
 * @package FornexPanel
 */

declare(strict_types=1);

use FornexPanel\Database\Database;
use FornexPanel\Fornex\Client;
use FornexPanel\Http\View;
use FornexPanel\Kernel\App;
use FornexPanel\Kernel\Config;
use FornexPanel\Kernel\Env;
use FornexPanel\Kernel\HttpException;
use FornexPanel\Kernel\Logger;
use FornexPanel\Kernel\Router;
use FornexPanel\Kernel\Session;
use FornexPanel\Security\Auth;
use FornexPanel\Security\Csrf;
use FornexPanel\Service\AuditService;
use FornexPanel\Service\DnsResolver;
use FornexPanel\Service\NsService;
use FornexPanel\Service\PresetService;
use FornexPanel\Service\Services;
use FornexPanel\Service\TokenService;
use FornexPanel\Service\VerifyService;
use FornexPanel\Service\ZoneService;

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

/**
 * @return array{0: Config, 1: Services, 2: Logger}
 */
$build = static function (): array {
    $config = Config::fromEnvironment(require APP_ROOT . '/backend/config/defaults.php');

    date_default_timezone_set($config->string('app.timezone', 'UTC'));

    $logger = new Logger(
        $config->path('log.path'),
        $config->string('log.level', 'info'),
        $config->int('log.rotate_bytes', 5_242_880),
        $config->int('log.keep_files', 5),
    );

    $database = new Database($config->path('db.path'), $config->int('db.busy_timeout', 5000));
    $database->migrate(APP_ROOT . '/backend/migrations');

    $apiKey = Env::get('FORNEX_API_KEY', '');

    if ($apiKey === null || trim($apiKey) === '') {
        throw new \RuntimeException('FORNEX_API_KEY is not set in .env — the panel cannot reach the Fornex API.');
    }

    $client = new Client(
        $config->string('fornex.base_url', 'https://ru.fornex.com/api'),
        trim($apiKey),
        $config->int('fornex.timeout', 30),
        $config->int('fornex.retries', 2),
        $config->string('fornex.user_agent', 'fornex-control/1.1'),
    );

    $audit = new AuditService($database);
    $zone = new ZoneService($client, $audit, $logger, $config->path('snapshot.dir'));
    $presets = new PresetService(
        APP_ROOT . '/backend/config/presets.json',
        $config->string('fornex.default_ip'),
    );
    $resolver = new DnsResolver(
        $config->string('verify.resolver', '8.8.8.8'),
        $config->string('verify.authoritative', 'ns1.fornex.com'),
    );
    $ns = new NsService($client, $zone, $audit, $resolver, $logger);
    $verify = new VerifyService($zone, $ns, $resolver);
    $tokens = new TokenService($database);

    $session = new Session(
        $config->string('session.name', 'fnx_session'),
        $config->bool('session.secure', true),
        $config->int('session.idle', 1800),
    );

    $auth = new Auth($config, $database, $session, $tokens, $logger, $audit);
    $csrf = new Csrf($session);
    $view = new View($config, $auth, $csrf);

    $services = new Services(
        $config,
        $logger,
        $database,
        $client,
        $audit,
        $zone,
        $presets,
        $verify,
        $ns,
        $tokens,
        $auth,
        $csrf,
        $view,
    );

    return [$config, $services, $logger];
};

[$config, $services, $logger] = $build();

$router = new Router(require APP_ROOT . '/backend/config/routes.php');

return new App($config, $router, $services, $logger);
