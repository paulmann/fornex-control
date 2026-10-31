<?php

/**
 * Защита от подделки запроса: токен в сессии, заголовок X-CSRF-Token.
 *
 * Для запросов с Bearer-токеном CSRF не нужен: браузер такие запросы
 * автоматически не отправляет.
 *
 * @package FornexPanel
 */

declare(strict_types=1);

namespace FornexPanel\Security;

use FornexPanel\Kernel\HttpException;
use FornexPanel\Kernel\Request;
use FornexPanel\Kernel\Session;

final class Csrf
{
    public function __construct(private readonly Session $session)
    {
    }

    public function token(): string
    {
        $token = $this->session->get('_csrf');

        if (!is_string($token) || strlen($token) < 32) {
            $token = bin2hex(random_bytes(24));
            $this->session->set('_csrf', $token);
        }

        return $token;
    }

    public function verify(Request $request): void
    {
        $expected = $this->session->get('_csrf');

        if (!is_string($expected) || $expected === '') {
            throw new HttpException(419, 'csrf', 'Страница устарела: обновите её и повторите действие.');
        }

        $given = (string) ($request->header('x-csrf-token') ?? $request->input('_csrf', ''));

        if ($given === '' || !hash_equals($expected, $given)) {
            throw new HttpException(419, 'csrf', 'Неверный токен формы: обновите страницу и повторите действие.');
        }
    }
}
