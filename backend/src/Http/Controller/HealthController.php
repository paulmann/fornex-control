<?php

/**
 * Служебный ответ для мониторинга: жив ли процесс и какая версия.
 * Секретов здесь нет, доступ ограничен правилом nginx по адресу источника.
 *
 * @package FornexPanel
 */

declare(strict_types=1);

namespace FornexPanel\Http\Controller;

use FornexPanel\Kernel\Request;
use FornexPanel\Kernel\Response;

final class HealthController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->ok($request, [
            'status' => 'ok',
            'version' => $this->s->config->string('app.version', '1.0.0'),
            'time' => gmdate('c'),
            'php' => PHP_VERSION,
        ]);
    }
}
