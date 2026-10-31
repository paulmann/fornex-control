# 06 · Командная строка

Вход: `php backend/bin/fornex <команда> [ключи]`.

Настройки — тот же файл, что у панели (`FORNEX_ENV` → `.env` → `../fornex.env`); если файла нет,
читается окружение. Без `FORNEX_API_KEY` команда выходит с кодом `2` и говорит, где ключ должен
лежать.

## Правило безопасности

Каждая изменяющая команда по умолчанию — **сухой прогон**: печатает план, ничего не пишет и
выходит с кодом `0`. Для записи нужен `--apply`. Это то же правило, что в интерфейсе
(предпросмотр → применение), и оно делает безопасным случайный запуск из cron или из hook-а.

## Общие ключи

| Ключ | Значение |
|---|---|
| `--apply` | выполнить изменение (без него — только план) |
| `--json` | машиночитаемый вывод |
| `--param:ключ=значение` | параметр пресета, можно повторять |
| `--note="…"` | примечание к снимку и записи журнала |

## Команды

| Команда | Ключи | Что делает |
|---|---|---|
| `help` | — | список команд |
| `domains` | — | зоны аккаунта |
| `zone:show <домен>` | `--json` | записи таблицей или JSON |
| `zone:snapshot <домен>` | `--note` | снимок в `SNAPSHOT_DIR`, печатает путь и sha256 |
| `entry:add <домен>` | `--host --type --value [--prio] [--ttl] [--apply]` | добавить запись |
| `entry:update <домен>` | `--id --host --type --value [--prio] [--ttl] [--apply]` | изменить запись по `id` |
| `entry:delete <домен>` | `--id [--apply]` | удалить по числовому `id` |
| `preset:list` | — | пресеты из `backend/config/presets.json` |
| `preset:apply <домен> <пресет>` | `--param:…` `--dkim-selector` `--mail-host` `--apply` | план или применение пресета |
| `verify <домен>` | `--json` | отчёт: NS, MX и цели, SPF, DKIM, DMARC, A `@`/`www`, PTR |
| `ns:status <домен>` | `--json` | делегирование и инструкция регистратору |
| `ns:apply <домен>` | `--ns a,b,c` `--apply` | сменить NS (только домен, заказанный в Fornex) |
| `domain:add <домен>` | `--ip` `--apply` | создать зону (Fornex может ответить 403) |
| `audit` | `--domain=` `--limit=30` `--json` | последние записи журнала |

Коды возврата: `0` — успех (включая сухой прогон), `1` — операция не удалась или отклонена,
`2` — ошибка использования или настроек.

## Примеры

```bash
# что есть в аккаунте
php backend/bin/fornex domains

# одна запись: план, затем запись
php backend/bin/fornex entry:add example.com --host www --type A --value 203.0.113.10 --ttl 300
php backend/bin/fornex entry:add example.com --host www --type A --value 203.0.113.10 --ttl 300 --apply

# мастер Google по шагам
php backend/bin/fornex preset:apply example.com google-workspace \
    --param:verification='google-site-verification=…' --apply
php backend/bin/fornex preset:apply example.com google-workspace --apply
php backend/bin/fornex preset:apply example.com google-workspace \
    --param:dkim='v=DKIM1; k=rsa; p=MIIB…' --apply
php backend/bin/fornex preset:apply example.com google-workspace \
    --param:dmarc='v=DMARC1; p=none; rua=mailto:postmaster@example.com' --apply

# проверка и журнал
php backend/bin/fornex verify example.com
php backend/bin/fornex audit --domain=example.com --limit=20
```

## Шаги и командная строка

В API шаг выбирается параметром `step`, в CLI — тем, какой параметр передан: `preset:apply`
собирает все записи, которые может собрать из имеющихся параметров. Практическое следствие:
мастер удобнее вести из интерфейса или по HTTP, а из CLI запускать готовые комбинации
(«почта и SPF», «DKIM», «DMARC») по одной.

## Для скриптов

- `--json` стабилен: `{ok: true, …}` при успехе, `{ok: false, error: {…}}` при ошибке.
- Автор действий из CLI в журнале — `cli`, поэтому изменение скриптом отличимо от действия
  человека.
- Снимок снимается до изменения, как и в интерфейсе: неудачный прогон всё равно оставляет снимок
  состояния, которое собирались менять.
