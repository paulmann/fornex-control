<?php

/**
 * Минимальный PSR-4 автозагрузчик без зависимостей.
 *
 * @package FornexPanel
 */

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'FornexPanel\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require $file;
    }
});
