<?php

/**
 * Сессия панели: cookie только httpOnly, SameSite=Lax, при HTTPS — Secure.
 *
 * @package FornexPanel
 */

declare(strict_types=1);

namespace FornexPanel\Kernel;

final class Session
{
    private bool $started = false;

    public function __construct(
        private readonly string $name,
        private readonly bool $secure,
        private readonly int $idle = 1800,
    ) {
    }

    public function start(): void
    {
        if ($this->started || PHP_SAPI === 'cli') {
            return;
        }

        session_name($this->name);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'httponly' => true,
            'secure' => $this->secure,
            'samesite' => 'Lax',
        ]);

        session_start();
        $this->started = true;

        $last = (int) ($_SESSION['_seen'] ?? 0);

        if ($last > 0 && time() - $last > $this->idle) {
            $this->destroy();
            session_start();
        }

        $_SESSION['_seen'] = time();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->start();

        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->start();
        $_SESSION[$key] = $value;
    }

    public function forget(string $key): void
    {
        $this->start();
        unset($_SESSION[$key]);
    }

    public function regenerate(): void
    {
        $this->start();

        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public function destroy(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_destroy();
        }

        $this->started = false;
    }
}
