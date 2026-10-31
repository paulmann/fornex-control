<?php

/**
 * Оболочка SPA: один HTML-документ для всех адресов панели.
 *
 * В документ попадают только неопасные значения: имя, версия, признак входа,
 * CSRF-токен. Ключ API и хэш пароля здесь не появляются никогда.
 *
 * @package FornexPanel
 */

declare(strict_types=1);

namespace FornexPanel\Http;

use FornexPanel\Kernel\Config;
use FornexPanel\Kernel\Request;
use FornexPanel\Kernel\Response;
use FornexPanel\Security\Auth;
use FornexPanel\Security\Csrf;
use FornexPanel\Support\Json;

final class View
{
    public function __construct(
        private readonly Config $config,
        private readonly Auth $auth,
        private readonly Csrf $csrf,
    ) {
    }

    public function shell(Request $request): Response
    {
        $logged = $this->auth->isAuthenticated();

        $base = rtrim($this->config->string('app.base'), '/');
        $boot = [
            'name' => $this->config->string('app.name', 'Fornex Control'),
            'version' => $this->config->string('app.version', '1.0.0'),
            'base' => $base,
            'logged_in' => $logged,
            'csrf' => $logged ? $this->csrf->token() : '',
            'default_ip' => $this->config->string('fornex.default_ip'),
        ];

        $payload = Json::encode($boot);
        $title = htmlspecialchars($boot['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        // Версия в ссылках на статику: после выкладки браузер берёт новый файл,
        // а не закешированный — иначе правки интерфейса «не доезжают».
        $version = rawurlencode($boot['version']);

        $html = <<<HTML
<!doctype html>
<html lang="ru" data-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="color-scheme" content="dark light">
  <meta name="robots" content="noindex, nofollow">
  <title>{$title}</title>
  <link rel="icon" href="favicon.svg" type="image/svg+xml">
  <link rel="stylesheet" href="assets/css/app.css?v={$version}">
  <link rel="stylesheet" href="assets/css/fornex.css?v={$version}">
</head>
<body>
  <a class="skip-link" href="#content">К содержимому</a>
  <div id="app" class="app-boot">
    <div class="boot-screen" role="status" aria-live="polite">
      <span class="spinner" aria-hidden="true"></span>
      <span>Загрузка панели…</span>
    </div>
  </div>
  <noscript><div class="noscript">Панель требует JavaScript.</div></noscript>
  <div id="toasts" class="toasts" aria-live="polite" aria-atomic="false"></div>
  <div id="overlay-root"></div>
  <script id="boot-data" type="application/json">{$payload}</script>
  <script type="module" src="assets/js/app.js?v={$version}"></script>
</body>
</html>
HTML;

        return Response::html($html)->withHeader('Cache-Control', 'no-store');
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
