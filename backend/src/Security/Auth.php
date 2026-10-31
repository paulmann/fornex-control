<?php

/**
 * Вход в панель и проверка прав.
 *
 * Две роли доступа:
 *  - человек в браузере: логин + пароль, пароль хранится хэшем в .env;
 *  - скрипт: Bearer-токен из панели, с флагом «только чтение» по умолчанию.
 *
 * Подбор пароля ограничен по IP: не более N попыток за окно (настройки security.*).
 *
 * @package FornexPanel
 */

declare(strict_types=1);

namespace FornexPanel\Security;

use FornexPanel\Database\Database;
use FornexPanel\Kernel\Config;
use FornexPanel\Kernel\HttpException;
use FornexPanel\Kernel\Logger;
use FornexPanel\Kernel\Request;
use FornexPanel\Kernel\Session;
use FornexPanel\Service\AuditService;
use FornexPanel\Service\TokenService;

final class Auth
{
    private ?string $actor = null;

    private bool $viaToken = false;

    private bool $writeAllowed = true;

    public function __construct(
        private readonly Config $config,
        private readonly Database $db,
        private readonly Session $session,
        private readonly TokenService $tokens,
        private readonly Logger $logger,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function login(string $login, string $password, string $ip): array
    {
        $this->assertAttemptsAllowed($ip);

        $expectedLogin = $this->config->string('auth.login', 'admin');
        $hash = $this->config->string('auth.hash');

        $ok = $hash !== '' && hash_equals($expectedLogin, $login) && password_verify($password, $hash);

        if (!$ok) {
            $this->db->insert('login_attempts', ['created_at' => time(), 'ip' => $ip]);
            $this->logger->warning('неудачный вход в панель', ['ip' => $ip, 'login' => $login]);

            throw new HttpException(401, 'bad_credentials', 'Неверный логин или пароль.');
        }

        $this->db->exec('DELETE FROM login_attempts WHERE ip = :ip', ['ip' => $ip]);
        $this->session->regenerate();
        $this->session->set('auth', true);
        $this->session->set('login', $login);
        $this->session->set('_seen', time());

        $this->logger->info('вход в панель', ['ip' => $ip, 'login' => $login]);

        return ['login' => $login];
    }

    public function logout(): void
    {
        $this->session->forget('auth');
        $this->session->forget('login');
        $this->session->destroy();
    }

    /** Бросает 401, если доступа нет; для токена — ещё и 403 на запись. */
    public function requireUser(Request $request, bool $write = true): void
    {
        $token = $request->bearer();

        if ($token !== null) {
            $row = $this->tokens->verify($token);

            if ($row === null) {
                throw HttpException::unauthorized('Токен недействителен или отозван.');
            }

            $this->viaToken = true;
            $this->writeAllowed = (int) ($row['write_access'] ?? 0) === 1;
            $this->actor = 'token:' . $row['name'];

            if ($write && !$this->writeAllowed) {
                throw HttpException::forbidden('Токен только для чтения.');
            }

            return;
        }

        if ($this->session->get('auth') !== true) {
            $this->autoLoginIfTrusted($request);
        }

        if ($this->session->get('auth') !== true) {
            throw HttpException::unauthorized();
        }

        $this->actor = 'panel:' . (string) $this->session->get('login', 'admin');
    }

    public function isTokenRequest(Request $request): bool
    {
        return $request->bearer() !== null;
    }

    /**
     * Автовход с доверенного адреса — так же, как в панели reg.ru:
     * `AUTO_LOGIN_IP` в .env, пустое значение выключает механизм.
     * Вход пишется в журнал, чтобы «кто зашёл без пароля» не было тайной.
     */
    public function autoLoginIfTrusted(Request $request): void
    {
        $trusted = trim($this->config->string('auth.auto_login_ip'));

        if ($trusted === '' || $request->ip !== $trusted || $this->session->get('auth') === true) {
            return;
        }

        $login = $this->config->string('auth.login', 'admin');

        $this->session->regenerate();
        $this->session->set('auth', true);
        $this->session->set('login', $login);
        $this->session->set('_seen', time());

        $this->actor = 'panel:autologin';
        $this->logger->info('автовход с доверенного адреса', ['ip' => $trusted]);
        $this->audit->record('panel:autologin', 'auth.autologin', '', 'вход без пароля с доверенного адреса', false, 'ok', [
            'ip' => $trusted,
            'login' => $login,
        ]);
    }

    public function actor(): string
    {
        return $this->actor ?? 'anonymous';
    }

    public function isAuthenticated(): bool
    {
        return $this->session->get('auth') === true;
    }

    public function isViaToken(): bool
    {
        return $this->viaToken;
    }

    private function assertAttemptsAllowed(string $ip): void
    {
        $limit = $this->config->int('security.login_attempts', 10);
        $window = $this->config->int('security.login_window', 900);
        $since = time() - $window;

        $row = $this->db->one(
            'SELECT COUNT(*) AS attempts FROM login_attempts WHERE ip = :ip AND created_at >= :since',
            ['ip' => $ip, 'since' => $since],
        );

        $attempts = (int) ($row['attempts'] ?? 0);

        if ($attempts >= $limit) {
            throw new HttpException(429, 'rate_limited', sprintf(
                'Слишком много попыток входа. Повторите через %d минут.',
                max(1, (int) ceil($window / 60)),
            ));
        }

        // чистим старые записи, чтобы таблица не росла
        $this->db->exec('DELETE FROM login_attempts WHERE created_at < :since', ['since' => $since - 86400]);
    }
}
