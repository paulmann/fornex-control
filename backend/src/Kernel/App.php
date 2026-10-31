<?php

/**
 * Ядро приложения: сборка зависимостей, разбор маршрута, проверка прав,
 * обработка ошибок и защитные заголовки.
 *
 * @package FornexPanel
 */

declare(strict_types=1);

namespace FornexPanel\Kernel;

use FornexPanel\Http\ErrorPage;
use FornexPanel\Service\Services;
use Throwable;

final class App
{
    public function __construct(
        private readonly Config $config,
        private readonly Router $router,
        private readonly Services $services,
        private readonly Logger $logger,
    ) {
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function services(): Services
    {
        return $this->services;
    }

    public function run(): never
    {
        $this->handle(Request::fromGlobals())->send();

        exit;
    }

    public function handle(Request $request): Response
    {
        $request = $this->stripBase($request);

        // Как в reg.ru: доверенный адрес входит без пароля. Делается до маршрутизации,
        // чтобы и оболочка SPA, и API видели одну и ту же сессию.
        $this->services->auth->autoLoginIfTrusted($request);

        $requestId = bin2hex(random_bytes(6));
        $started = microtime(true);

        try {
            $response = $this->dispatch($request);
        } catch (HttpException $exception) {
            $response = $this->errorResponse($request, $exception->status, $exception->codeName, $exception->getMessage(), $exception->details);
        } catch (Throwable $exception) {
            $this->logger->error('необработанная ошибка: ' . $exception->getMessage(), [
                'path' => $request->path,
                'request' => $requestId,
                'type' => $exception::class,
                'file' => $exception->getFile() . ':' . $exception->getLine(),
            ]);

            $response = $this->errorResponse($request, 500, 'internal_error', 'Внутренняя ошибка панели. Подробности в журнале.', [
                'request' => $requestId,
            ]);
        }

        return $response
            ->withHeader('X-Request-Id', $requestId)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Server-Timing', sprintf('app;dur=%.1f', (microtime(true) - $started) * 1000));
    }

    /** Снимает префикс подкаталога (/fornex), если он задан настройкой app.base. */
    private function stripBase(Request $request): Request
    {
        $base = rtrim($this->config->string('app.base'), '/');

        if ($base === '' || !str_starts_with($request->path, $base)) {
            return $request;
        }

        $path = substr($request->path, strlen($base));

        return $request->withPath($path === '' ? '/' : $path, $request->query);
    }

    private function dispatch(Request $request): Response
    {
        $match = $this->router->match($request);

        if ($match === null) {
            if (str_starts_with($request->path, '/api/')) {
                throw HttpException::notFound('Метод API не найден.');
            }

            // всё, что не /api/ — отдаём оболочку SPA (клиент сам разберёт адрес)
            return $this->services->view->shell($request);
        }

        /** @var array{method: string, path: string, handler: array{class-string, string}, auth: bool, csrf: bool, write: bool} $route */
        $route = $match['route'];

        if ($route['auth']) {
            $this->services->auth->requireUser($request, (bool) ($route['write'] ?? true));
        }

        if ($route['csrf'] && !$this->services->auth->isTokenRequest($request)) {
            $this->services->csrf->verify($request);
        }

        [$class, $method] = $route['handler'];
        $controller = $this->services->controller($class);

        /** @var Response $response */
        $response = $controller->{$method}($request, $match['params']);

        return $response;
    }

    /** @param array<string, mixed> $details */
    private function errorResponse(Request $request, int $status, string $code, string $message, array $details): Response
    {
        if (!str_starts_with($request->path, '/api/')) {
            return Response::html(ErrorPage::render($status, $message), $status);
        }

        return Response::error($code, $message, $status, $details);
    }
}
