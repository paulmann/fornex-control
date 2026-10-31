<?php

/**
 * Записи зоны: чтение, создание, правка по id, удаление по id, снимок.
 *
 * Удаление и правка принимают только числовой id — «удалить по маске» здесь
 * нет намеренно: одна неверная маска уносит боевые MX и SPF.
 *
 * @package FornexPanel
 */

declare(strict_types=1);

namespace FornexPanel\Http\Controller;

use FornexPanel\Kernel\Request;
use FornexPanel\Kernel\Response;
use FornexPanel\Support\Str;

final class ZoneController extends Controller
{
    public function index(Request $request, array $params): Response
    {
        $domain = $this->domain($params);
        $entries = $this->s->zone->entries($domain);

        return $this->ok($request, [
            'domain' => $domain,
            'entries' => $entries,
            'summary' => $this->s->zone->summary($domain),
        ]);
    }

    public function create(Request $request, array $params): Response
    {
        $domain = $this->domain($params);

        $record = [
            'host' => $request->string('host'),
            'type' => $request->string('type'),
            'value' => $request->string('value'),
            'prio' => $request->input('prio'),
            'ttl' => $request->input('ttl'),
        ];

        $created = $this->s->zone->addEntry($domain, $record, $this->s->auth->actor());

        return $this->ok($request, ['created' => $created, 'record' => $record]);
    }

    public function update(Request $request, array $params): Response
    {
        $domain = $this->domain($params);
        $id = $this->id($params);

        $record = [
            'host' => $request->string('host'),
            'type' => $request->string('type'),
            'value' => $request->string('value'),
            'prio' => $request->input('prio'),
            'ttl' => $request->input('ttl'),
        ];

        $updated = $this->s->zone->updateEntry($domain, $id, $record, $this->s->auth->actor());

        return $this->ok($request, ['updated' => $updated, 'id' => $id]);
    }

    public function delete(Request $request, array $params): Response
    {
        $domain = $this->domain($params);
        $id = $this->id($params);

        $this->s->zone->deleteEntry($domain, $id, $this->s->auth->actor());

        return $this->ok($request, ['deleted' => $id]);
    }

    public function snapshot(Request $request, array $params): Response
    {
        $domain = $this->domain($params);
        $kind = $request->string('kind', 'manual');
        $meta = $this->s->zone->snapshot($domain, $kind, $request->string('note'));

        $this->s->audit->registerSnapshot($domain, $kind, $meta, $request->string('note'));

        return $this->ok($request, ['snapshot' => $meta]);
    }

    public function zoneFile(Request $request, array $params): Response
    {
        $domain = $this->domain($params);

        return Response::text($this->s->client->zoneFile($domain))
            ->withHeader('Content-Disposition', 'inline; filename="' . $domain . '.zone"');
    }
}
