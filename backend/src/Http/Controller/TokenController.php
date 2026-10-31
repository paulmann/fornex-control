<?php

/**
 * Токены скриптов: список, выпуск, отзыв.
 *
 * @package FornexPanel
 */

declare(strict_types=1);

namespace FornexPanel\Http\Controller;

use FornexPanel\Kernel\Request;
use FornexPanel\Kernel\Response;

final class TokenController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->ok($request, ['tokens' => $this->s->tokens->list()]);
    }

    public function create(Request $request): Response
    {
        $created = $this->s->tokens->create($request->string('name'), $request->bool('write', false));

        $this->s->audit->record($this->s->auth->actor(), 'token.create', '', 'создан токен ' . $created['name'], false, 'ok', [
            'id' => $created['id'],
            'write' => $created['write_access'],
        ]);

        return $this->ok($request, ['token' => $created]);
    }

    public function revoke(Request $request, array $params): Response
    {
        $id = $this->id($params);
        $done = $this->s->tokens->revoke($id);

        $this->s->audit->record($this->s->auth->actor(), 'token.revoke', '', 'отозван токен id ' . $id, false, $done ? 'ok' : 'missing');

        return $this->ok($request, ['revoked' => $done, 'id' => $id]);
    }
}
