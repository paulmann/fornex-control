<?php

/**
 * Табличный маршрутизатор.
 *
 * Таблица маршрутов лежит в backend/config/routes.php и читается один раз.
 * Шаблон `{name}` совпадает с одним сегментом пути. 404 (нет маршрута) и
 * 405 (путь есть, метод другой) различаются — иначе ошибки в клиенте
 * выглядят как «страница не найдена».
 *
 * @package FornexPanel
 */

declare(strict_types=1);

namespace FornexPanel\Kernel;

final class Router
{
    /** @var list<array{method: string, path: string, regex: string, handler: array{class-string, string}, auth: bool, csrf: bool, write: bool}> */
    private array $routes = [];

    /**
     * @param list<array{method: string, path: string, handler: array{class-string, string}, auth?: bool, csrf?: bool}> $routes
     */
    public function __construct(array $routes)
    {
        foreach ($routes as $route) {
            $path = '/' . trim($route['path'], '/');
            $regex = '#^' . preg_replace(
                '/\\\\\{([a-zA-Z_][a-zA-Z0-9_]*)\\\\\}/',
                '(?P<$1>[^/]+)',
                preg_quote($path, '#'),
            ) . '$#';

            $this->routes[] = [
                'method' => strtoupper($route['method']),
                'path' => $path,
                'regex' => $regex,
                'handler' => $route['handler'],
                'auth' => $route['auth'] ?? true,
                'csrf' => $route['csrf'] ?? $this->isWriteMethod($route['method']),
                'write' => $route['write'] ?? $this->isWriteMethod($route['method']),
            ];
        }
    }

    /** @return list<array{method: string, path: string, regex: string, handler: array{class-string, string}, auth: bool, csrf: bool, write: bool}> */
    private function isWriteMethod(string $method): bool
    {
        return in_array(strtoupper($method), ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }
    /**
     * @return array{route: array<string, mixed>, params: array<string, string>}|null
     */
    public function match(Request $request): ?array
    {
        $allowed = [];

        foreach ($this->routes as $index => $route) {
            if (preg_match($route['regex'], $request->path, $matches) !== 1) {
                continue;
            }

            if ($route['method'] !== $request->method) {
                $allowed[] = $route['method'];

                continue;
            }

            $params = [];
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = rawurldecode($value);
                }
            }

            return ['route' => $this->routes[$index], 'params' => $params];
        }

        if ($allowed !== []) {
            throw new HttpException(
                405,
                'method_not_allowed',
                'Этот метод не поддерживается для адреса.',
                ['allowed' => array_values(array_unique($allowed))],
                ['Allow' => implode(', ', array_values(array_unique($allowed)))],
            );
        }

        return null;
    }
}
