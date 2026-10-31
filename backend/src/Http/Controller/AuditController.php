<?php

/**
 * Журнал действий и снимки зон.
 *
 * @package FornexPanel
 */

declare(strict_types=1);

namespace FornexPanel\Http\Controller;

use FornexPanel\Kernel\Request;
use FornexPanel\Kernel\Response;

final class AuditController extends Controller
{
    public function index(Request $request): Response
    {
        $domain = (string) ($request->query['domain'] ?? '');
        $limit = $request->int('limit', 100);

        return $this->ok($request, [
            'events' => $this->s->audit->list($limit, $domain),
            'snapshots' => $this->s->audit->snapshots($domain, 50),
        ]);
    }
}
