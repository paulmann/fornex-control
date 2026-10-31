<?php

/**
 * Пресеты: список, состояние шагов, предпросмотр, применение.
 *
 * Логика «сначала план, потом запись» вынесена в ZoneService::plan — здесь
 * только сборка параметров и вызов. Поэтому панель и CLI дают одинаковый
 * результат на одних и тех же входных данных.
 *
 * Шаг (`step`) ограничивает применение одной частью пресета: у Google это
 * подтверждение домена, MX+SPF, DKIM и DMARC — их нельзя валить в одну кучу,
 * потому что DKIM выдаётся только после подтверждения домена.
 *
 * @package FornexPanel
 */

declare(strict_types=1);

namespace FornexPanel\Http\Controller;

use FornexPanel\Kernel\HttpException;
use FornexPanel\Kernel\Request;
use FornexPanel\Kernel\Response;

final class PresetController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->ok($request, [
            'presets' => $this->s->presets->all(),
            'default_ip' => $this->s->config->string('fornex.default_ip'),
        ]);
    }

    /** Состояние шагов: что уже в зоне, что заблокировано порядком. */
    public function wizard(Request $request, array $params): Response
    {
        $domain = $this->domain($params);
        $presetId = (string) ($params['preset'] ?? '');

        $overview = $this->s->presets->stepsOverview(
            $presetId,
            $domain,
            [],
            $this->s->zone->entries($domain),
        );

        return $this->ok($request, ['wizard' => $overview]);
    }

    public function preview(Request $request, array $params): Response
    {
        $domain = $this->domain($params);
        $presetId = (string) ($params['preset'] ?? '');
        $params0 = (array) ($request->input('params', []) ?: []);
        $step = $this->step($request);

        // Предпросмотр не падает на пустом поле шага — возвращает примечание.
        $built = $this->s->presets->build($presetId, $domain, $params0, $step, false);
        $plan = $this->s->zone->plan($domain, $built['records']);

        return $this->ok($request, [
            'domain' => $domain,
            'preset' => ['id' => $built['id'], 'title' => $built['title']],
            'step' => $built['step'],
            'records' => $built['records'],
            'plan' => $plan,
            'notes' => $built['notes'],
            'counts' => [
                'create' => count($plan['create']),
                'update' => count($plan['update']),
                'skip' => count($plan['skip']),
                'conflict' => count($plan['conflict']),
            ],
        ]);
    }

    public function apply(Request $request, array $params): Response
    {
        $domain = $this->domain($params);
        $presetId = (string) ($params['preset'] ?? '');
        $params0 = (array) ($request->input('params', []) ?: []);
        $step = $this->step($request);
        $dryRun = $request->bool('dry_run', false);
        $note = $request->string('note', 'пресет ' . $presetId . ($step !== null ? ' / шаг ' . $step : ''));

        if ($this->s->presets->requiresConfirm($presetId, $step) && $request->string('confirm') !== $domain && !$dryRun) {
            throw HttpException::conflict('Применение этого шага требует подтверждения: введите имя домена.', [
                'expected' => $domain,
            ]);
        }

        $built = $this->s->presets->build($presetId, $domain, $params0, $step);
        $plan = $this->s->zone->plan($domain, $built['records']);
        $result = $this->s->zone->applyPlan($domain, $plan, $dryRun, $this->s->auth->actor(), $note);

        return $this->ok($request, [
            'domain' => $domain,
            'preset' => ['id' => $built['id'], 'title' => $built['title']],
            'step' => $built['step'],
            'plan' => $plan,
            'result' => $result,
        ]);
    }

    private function step(Request $request): ?string
    {
        $step = trim((string) $request->input('step', ''));

        return $step === '' ? null : $step;
    }
}
