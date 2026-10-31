<?php

/**
 * Базовый контроллер: получает контейнер сервисов и умеет отдавать JSON.
 *
 * @package FornexPanel
 */

declare(strict_types=1);

namespace FornexPanel\Http\Controller;

use FornexPanel\Kernel\HttpException;
use FornexPanel\Kernel\Request;
use FornexPanel\Kernel\Response;
use FornexPanel\Service\Services;
use FornexPanel\Support\Str;

abstract class Controller
{
    public function __construct(protected readonly Services $s)
    {
    }

    /** Домен из пути — единственное место, где он валидируется для всех методов. */
    protected function domain(array $params): string
    {
        $domain = Str::domain($params['domain'] ?? '');

        if ($domain === null) {
            throw HttpException::badRequest('Некорректное имя домена: ' . (string) ($params['domain'] ?? ''));
        }

        return $domain;
    }

    protected function id(array $params): int
    {
        $id = (int) ($params['id'] ?? 0);

        if ($id <= 0) {
            throw HttpException::badRequest('Некорректный идентификатор записи.');
        }

        return $id;
    }

    /** @param array<string, mixed> $data */
    protected function ok(Request $request, array $data = []): Response
    {
        return Response::ok($data + ['request' => $request->path]);
    }
}
