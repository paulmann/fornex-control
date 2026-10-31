<?php

/**
 * Таблица маршрутов API.
 *
 * auth=false только у входа и служебного health; csrf=false — у входа,
 * потому что токена сессии ещё нет.
 *
 * @return list<array{method: string, path: string, handler: array{0: class-string, 1: string}, auth?: bool, csrf?: bool}>
 */

declare(strict_types=1);

use FornexPanel\Http\Controller\AuditController;
use FornexPanel\Http\Controller\AuthController;
use FornexPanel\Http\Controller\DomainController;
use FornexPanel\Http\Controller\HealthController;
use FornexPanel\Http\Controller\MetaController;
use FornexPanel\Http\Controller\NsController;
use FornexPanel\Http\Controller\PresetController;
use FornexPanel\Http\Controller\TokenController;
use FornexPanel\Http\Controller\VerifyController;
use FornexPanel\Http\Controller\ZoneController;

return [
    // сессия и вход
    ['method' => 'GET', 'path' => '/api/csrf', 'handler' => [AuthController::class, 'csrf'], 'auth' => false, 'csrf' => false],
    ['method' => 'POST', 'path' => '/api/login', 'handler' => [AuthController::class, 'login'], 'auth' => false, 'csrf' => false],
    ['method' => 'POST', 'path' => '/api/logout', 'handler' => [AuthController::class, 'logout']],
    ['method' => 'GET', 'path' => '/api/me', 'handler' => [AuthController::class, 'me']],

    // служебное
    ['method' => 'GET', 'path' => '/api/health', 'handler' => [HealthController::class, 'index'], 'auth' => false],
    ['method' => 'GET', 'path' => '/api/meta', 'handler' => [MetaController::class, 'index']],

    // домены
    ['method' => 'GET', 'path' => '/api/domains', 'handler' => [DomainController::class, 'index']],
    ['method' => 'POST', 'path' => '/api/domains', 'handler' => [DomainController::class, 'add']],
    ['method' => 'GET', 'path' => '/api/domains/{domain}', 'handler' => [DomainController::class, 'show']],
    ['method' => 'DELETE', 'path' => '/api/domains/{domain}', 'handler' => [DomainController::class, 'destroy']],

    // зона
    ['method' => 'GET', 'path' => '/api/domains/{domain}/zone', 'handler' => [ZoneController::class, 'index']],
    ['method' => 'POST', 'path' => '/api/domains/{domain}/zone', 'handler' => [ZoneController::class, 'create']],
    ['method' => 'PUT', 'path' => '/api/domains/{domain}/zone/{id}', 'handler' => [ZoneController::class, 'update']],
    ['method' => 'DELETE', 'path' => '/api/domains/{domain}/zone/{id}', 'handler' => [ZoneController::class, 'delete']],
    ['method' => 'POST', 'path' => '/api/domains/{domain}/snapshot', 'handler' => [ZoneController::class, 'snapshot']],
    ['method' => 'GET', 'path' => '/api/domains/{domain}/zone-file', 'handler' => [ZoneController::class, 'zoneFile']],

    // пресеты
    ['method' => 'GET', 'path' => '/api/presets', 'handler' => [PresetController::class, 'index']],
    ['method' => 'GET', 'path' => '/api/domains/{domain}/wizard/{preset}', 'handler' => [PresetController::class, 'wizard']],
    // предпросмотр ничего не пишет — доступен и токену «только чтение»
    ['method' => 'POST', 'path' => '/api/domains/{domain}/preset/{preset}/preview', 'handler' => [PresetController::class, 'preview'], 'write' => false],
    ['method' => 'POST', 'path' => '/api/domains/{domain}/preset/{preset}/apply', 'handler' => [PresetController::class, 'apply']],

    // проверка и NS
    ['method' => 'GET', 'path' => '/api/domains/{domain}/verify', 'handler' => [VerifyController::class, 'run']],
    ['method' => 'GET', 'path' => '/api/domains/{domain}/ns', 'handler' => [NsController::class, 'status']],
    ['method' => 'POST', 'path' => '/api/domains/{domain}/ns', 'handler' => [NsController::class, 'change']],

    // журнал и токены
    ['method' => 'GET', 'path' => '/api/audit', 'handler' => [AuditController::class, 'index']],
    ['method' => 'GET', 'path' => '/api/tokens', 'handler' => [TokenController::class, 'index']],
    ['method' => 'POST', 'path' => '/api/tokens', 'handler' => [TokenController::class, 'create']],
    ['method' => 'DELETE', 'path' => '/api/tokens/{id}', 'handler' => [TokenController::class, 'revoke']],
];
