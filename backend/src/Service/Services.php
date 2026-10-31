<?php

/**
 * Контейнер сервисов: собирается один раз в bootstrap и передаётся всем
 * потребителям (HTTP-контроллеры и CLI). Ручная сборка вместо магии автопоиска
 * зависимостей: граф маленький, а поведение предсказуемое.
 *
 * @package FornexPanel
 */

declare(strict_types=1);

namespace FornexPanel\Service;

use FornexPanel\Database\Database;
use FornexPanel\Fornex\Client;
use FornexPanel\Http\View;
use FornexPanel\Kernel\Config;
use FornexPanel\Kernel\Logger;
use FornexPanel\Security\Auth;
use FornexPanel\Security\Csrf;

final class Services
{
    public function __construct(
        public readonly Config $config,
        public readonly Logger $logger,
        public readonly Database $db,
        public readonly Client $client,
        public readonly AuditService $audit,
        public readonly ZoneService $zone,
        public readonly PresetService $presets,
        public readonly VerifyService $verify,
        public readonly NsService $ns,
        public readonly TokenService $tokens,
        public readonly Auth $auth,
        public readonly Csrf $csrf,
        public readonly View $view,
    ) {
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    public function controller(string $class): object
    {
        return new $class($this);
    }
}
