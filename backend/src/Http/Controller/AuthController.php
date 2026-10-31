<?php

/**
 * Вход, выход, состояние сессии.
 *
 * @package FornexPanel
 */

declare(strict_types=1);

namespace FornexPanel\Http\Controller;

use FornexPanel\Kernel\Request;
use FornexPanel\Kernel\Response;

final class AuthController extends Controller
{
    public function csrf(Request $request): Response
    {
        return $this->ok($request, [
            'csrf' => $this->s->csrf->token(),
            'logged_in' => $this->s->auth->isAuthenticated(),
        ]);
    }

    public function login(Request $request): Response
    {
        $result = $this->s->auth->login(
            $request->string('login'),
            // Пароль не обрезаем: в reg.ru он проверяется как есть, и лишний trim
            // сломал бы вход с паролем, у которого значимы края.
            (string) $request->input('password', ''),
            $request->ip,
        );

        return $this->ok($request, [
            'login' => $result['login'],
            'csrf' => $this->s->csrf->token(),
        ]);
    }

    public function logout(Request $request): Response
    {
        $this->s->auth->logout();

        return $this->ok($request);
    }

    public function me(Request $request): Response
    {
        return $this->ok($request, [
            'logged_in' => $this->s->auth->isAuthenticated(),
            'actor' => $this->s->auth->actor(),
            'via_token' => $this->s->auth->isViaToken(),
            'csrf' => $this->s->csrf->token(),
        ]);
    }
}
