<?php

/**
 * Проверка домена: DNS и зона Fornex.
 *
 * @package FornexPanel
 */

declare(strict_types=1);

namespace FornexPanel\Http\Controller;

use FornexPanel\Kernel\Request;
use FornexPanel\Kernel\Response;

final class VerifyController extends Controller
{
    public function run(Request $request, array $params): Response
    {
        $domain = $this->domain($params);

        $report = $this->s->verify->check($domain, [
            'dkim_selector' => $request->string('dkim_selector', 'google._domainkey'),
            'mail_host' => $request->string('mail_host', 'mail.' . $domain),
        ]);

        $this->s->audit->record(
            $this->s->auth->actor(),
            'verify.run',
            $domain,
            sprintf(
                'проверка: ок %d, предупр. %d, ошибок %d',
                $report['summary']['ok'] ?? 0,
                $report['summary']['warn'] ?? 0,
                $report['summary']['fail'] ?? 0,
            ),
            false,
            'ok',
            ['summary' => $report['summary']],
        );

        return $this->ok($request, ['report' => $report]);
    }
}
