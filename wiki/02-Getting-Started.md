# 02 · Установка

## Что нужно

| Компонент | Требование |
|---|---|
| PHP | 8.5 с расширениями `curl`, `json`, `mbstring`, `openssl`, `pdo_sqlite` |
| Веб-сервер | nginx + PHP-FPM (проверено) либо любой другой; для осмотра хватает `php -S` |
| Аккаунт Fornex | ключ Public API: кабинет `ru.fornex.com` → «Мой» → API |
| Место для состояния | каталог, доступный на запись PHP и недоступный по HTTP |

Зависимостей Composer и npm нет: дерево работает от `git clone` плюс PHP.

## Раскладка

```
/srv/fornex-control/          приложение
/srv/fornex.env               настройки (640, владелец — пользователь PHP, вне корня сайта)
/srv/fornex-control/var/      база, журнал, снимки (на запись, наружу не отдаётся)
```

Файл настроек может лежать и внутри каталога приложения как `.env`, но рекомендованное место —
уровнем выше: тогда ошибка в конфигурации nginx не сможет его отдать.

## Шаги

```bash
# 1. код
git clone https://github.com/paulmann/fornex-control.git /srv/fornex-control
chown -R www-data:www-data /srv/fornex-control

# 2. настройки
install -m 640 -o www-data -g www-data /srv/fornex-control/.env.example /srv/fornex.env
$EDITOR /srv/fornex.env        # FORNEX_API_KEY, ADMIN_PASSWORD_HASH

# 3. состояние
install -d -m 750 -o www-data -g www-data \
    /srv/fornex-control/var /srv/fornex-control/var/log /srv/fornex-control/var/snapshots

# 4. проверки до публикации
sudo -u www-data php /srv/fornex-control/backend/bin/fornex help
sudo -u www-data php /srv/fornex-control/tests/plan_test.php
sudo -u www-data php /srv/fornex-control/tools/lint.php
```

`php backend/bin/fornex help` — самая дешёвая проверка: он доказывает, что настройки читаются,
ключ на месте, база создаётся и миграции прошли.

## Пароль панели

Пароль хранится только хэшем:

```bash
php -r "echo password_hash('ваш-пароль', PASSWORD_DEFAULT), PHP_EOL;"
```

Строку целиком (без переноса строки) — в `ADMIN_PASSWORD_HASH`. Пустой хэш означает, что вход
запрещён: панель не пустит никого.

## nginx

Приложение в подкаталоге `/fornex/`:

```nginx
location ^~ /fornex/ {
    alias /srv/fornex-control/;
    index index.php;

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.5-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $request_filename;
    }

    location ~ ^/fornex/(backend|tests|tools|var)/ { deny all; }
    location ~ /\. { deny all; }
}
```

В этом случае в настройках обязательно `APP_BASE=/fornex`, иначе интерфейс будет просить
`/api/...` в корне сайта.

Полный рецепт, включая вариант «в корне сайта», ограничение по адресам, TLS-заголовки, бэкап и
обновление, — в [`docs/DEPLOY.md`](https://github.com/paulmann/fornex-control/blob/main/docs/DEPLOY.md).

## Проверка после установки

```bash
curl -s https://panel.example.com/fornex/api/health
# {"ok":true,"status":"ok","version":"1.1.0","php":"8.5.x","time":"…"}
```

`/api/health` не требует входа и не отдаёт ничего, кроме версии, — это то, что стоит опрашивать
мониторингу. Дальше откройте панель в браузере, войдите и посмотрите список зон.

## Локальный запуск без веб-сервера

```bash
cp .env.example .env
php -S 127.0.0.1:8080 index.php
# http://127.0.0.1:8080/
```

`index.php` распознаёт `cli-server` и отдаёт существующие статические файлы напрямую, поэтому
CSS и JavaScript работают без маршрутизатора. Режим однопоточный и не для боевой работы.

## Частые ошибки установки

| Симптом | Причина | Что делать |
|---|---|---|
| `Fornex Control: no configuration found` | нет ни `fornex.env`, ни `.env`, ни `FORNEX_ENV`, ни `FORNEX_API_KEY` в окружении | положить файл уровнем выше приложения |
| `FORNEX_API_KEY is not set in .env` | файл найден, ключ пуст | вписать ключ из кабинета |
| 500 без тела, в журнале «permission denied» | `var/` не доступен на запись | `chown -R www-data:www-data var` |
| Страница пустая, в консоли 404 на `assets/…` | `APP_BASE` не совпадает с подкаталогом | поставить `APP_BASE=/fornex` или пусто для корня |
