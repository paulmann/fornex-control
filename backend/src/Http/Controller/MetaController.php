<?php

/**
 * Сведения о конфигурации панели для интерфейса: версия, типовая IP, типы записей.
 *
 * @package FornexPanel
 */

declare(strict_types=1);

namespace FornexPanel\Http\Controller;

use FornexPanel\Kernel\Request;
use FornexPanel\Kernel\Response;
use FornexPanel\Support\Str;

final class MetaController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->ok($request, [
            'app' => [
                'name' => $this->s->config->string('app.name', 'Fornex Control'),
                'version' => $this->s->config->string('app.version', '1.0.0'),
                'env' => $this->s->config->string('app.env', 'production'),
            ],
            'fornex' => [
                'base_url' => $this->s->config->string('fornex.base_url'),
                'default_ip' => $this->s->config->string('fornex.default_ip'),
                'timeout' => $this->s->config->int('fornex.timeout', 30),
            ],
            'limits' => [
                'record_types' => Str::RECORD_TYPES,
                'delete_requires_id' => true,
                'snapshot_before_write' => true,
            ],
            'actor' => $this->s->auth->actor(),
        ]);
    }
}
