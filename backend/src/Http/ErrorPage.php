<?php

/**
 * Страница ошибки в стиле панели (для не-API адресов).
 *
 * @package FornexPanel
 */

declare(strict_types=1);

namespace FornexPanel\Http;

final class ErrorPage
{
    public static function render(int $status, string $message): string
    {
        $title = htmlspecialchars((string) $status, ENT_QUOTES, 'UTF-8');
        $text = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return <<<HTML
<!doctype html>
<html lang="ru" data-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>{$title} · Fornex Control</title>
  <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
  <div class="login-wrap">
    <div class="card login-card">
      <div class="card-head"><h1 class="page-title">{$title}</h1></div>
      <div class="card-body">
        <p class="muted">{$text}</p>
        <p><a class="btn" href="/fornex/">Вернуться в панель</a></p>
      </div>
    </div>
  </div>
</body>
</html>
HTML;
    }
}
