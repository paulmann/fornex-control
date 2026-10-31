<?php

/**
 * NS домена: состояние делегирования и смена (если домен заказан у Fornex).
 *
 * @package FornexPanel
 */

declare(strict_types=1);

namespace FornexPanel\Http\Controller;

use FornexPanel\Kernel\Request;
use FornexPanel\Kernel\Response;
use FornexPanel\Support\Str;

final class NsController extends Controller
{
    public function status(Request $request, array $params): Response
    {
        $domain = $this->domain($params);

        return $this->ok($request, ['ns' => $this->s->ns->status($domain)]);
    }

    public function change(Request $request, array $params): Response
    {
        $domain = $this->domain($params);

        $raw = $request->input('nss', $this->s->ns->desired());
        $list = is_array($raw) ? $raw : explode(',', (string) $raw);
        $list = array_values(array_filter(array_map(static fn ($item): string => Str::value((string) $item), $list)));

        $result = $this->s->ns->change($domain, $list, $this->s->auth->actor(), $request->bool('dry_run', true));

        return $this->ok($request, ['change' => $result]);
    }
}
