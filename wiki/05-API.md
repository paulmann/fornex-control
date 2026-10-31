# 05 · API

Базовый путь — тот, по которому отдаётся приложение (`APP_BASE`; пусто, если панель в корне
сайта). Все ответы — JSON, с заголовком `Cache-Control: no-store`.

## Формат ответа

```json
{"ok": true, "…": "поля маршрута", "request": "/api/domains"}
```

```json
{"ok": false, "error": {"code": "invalid_request", "message": "…", "details": {}}}
```

`details` появляется, только когда обработчику есть что добавить: допустимые методы, ближайшая
существующая запись, недостающий параметр.

## Коды ошибок

| Код | `code` | Когда |
|---|---|---|
| 401 | `unauthorized`, `bad_credentials` | нет сессии, нет токена, неверный пароль |
| 403 | `forbidden` | токен «только чтение» на пишущем маршруте |
| 404 | `not_found` | нет маршрута или домена в аккаунте |
| 405 | `method_not_allowed` | путь есть, метод другой (`Allow` в заголовке) |
| 409 | `conflict` | шаг требует подтверждения, которого нет |
| 422 | `invalid_request` | плохое имя домена, идентификатор, поля записи |
| 429 | `rate_limited` | слишком много попыток входа с одного адреса |
| 502 | `upstream_error` | API Fornex ответил ошибкой |
| 500 | `internal_error` | непредвиденное; в `production` сообщение обезличено |

## Авторизация

Два механизма, оба проверяет `Auth::requireUser`:

- **Сессия.** `POST /api/login` с `{"login": "…", "password": "…"}`. Cookie `HttpOnly`,
  `SameSite=Lax`, `Secure` при `SESSION_SECURE=1`. `AUTO_LOGIN_IP` выдаёт сессию без пароля с
  одного доверенного адреса.
- **Bearer-токен.** `Authorization: Bearer <токен>` на любом маршруте. Токен только для чтения,
  если выпущен без права записи; запись таким токеном — `403`.

Браузер и скрипт используют одни и те же маршруты.

## CSRF

Пишущие маршруты требуют `X-CSRF-Token`, если используется сессия. Токен выдаёт `GET /api/csrf`,
он же встроен в оболочку SPA. Запросам с bearer-токеном CSRF не нужен: у скрипта нет браузерной
сессии, которую можно угнать.

## Маршруты

`auth` — нужна сессия или токен. `csrf` — нужен `X-CSRF-Token` при работе через сессию.
`write` — отказ для токена «только чтение».

| Метод | Путь | auth | csrf | write | Что делает |
|---|---|---|---|---|---|
| GET | `/api/csrf` | нет | нет | нет | выдать CSRF-токен |
| POST | `/api/login` | нет | нет | да | создать сессию |
| POST | `/api/logout` | да | да | да | закрыть сессию |
| GET | `/api/me` | да | нет | нет | кто вошёл, состояние сессии |
| GET | `/api/health` | нет | нет | нет | `status`, `version`, `php`, `time` |
| GET | `/api/meta` | да | нет | нет | имя и версия, адрес API, типовой IP, типы записей |
| GET | `/api/domains` | да | нет | нет | зоны аккаунта |
| POST | `/api/domains` | да | да | да | создать зону (Fornex может ответить 403) |
| GET | `/api/domains/{domain}` | да | нет | нет | зона с записями |
| DELETE | `/api/domains/{domain}` | да | да | да | удалить зону (`confirm` = имя домена, `dry_run` поддерживается) |
| GET | `/api/domains/{domain}/zone` | да | нет | нет | записи |
| POST | `/api/domains/{domain}/zone` | да | да | да | добавить запись |
| PUT | `/api/domains/{domain}/zone/{id}` | да | да | да | изменить запись по `id` |
| DELETE | `/api/domains/{domain}/zone/{id}` | да | да | да | удалить запись по `id` |
| POST | `/api/domains/{domain}/snapshot` | да | да | да | снять снимок сейчас |
| GET | `/api/domains/{domain}/zone-file` | да | нет | нет | зона как BIND-файл |
| GET | `/api/presets` | да | нет | нет | каталог пресетов |
| GET | `/api/domains/{domain}/wizard/{preset}` | да | нет | нет | статусы шагов |
| POST | `/api/domains/{domain}/preset/{preset}/preview` | да | да | **нет** | план; ничего не пишется |
| POST | `/api/domains/{domain}/preset/{preset}/apply` | да | да | да | выполнить план (может требовать подтверждения) |
| GET | `/api/domains/{domain}/verify` | да | нет | нет | отчёт по DNS |
| GET | `/api/domains/{domain}/ns` | да | нет | нет | делегирование и инструкция |
| POST | `/api/domains/{domain}/ns` | да | да | да | сменить NS (`dry_run` поддерживается) |
| GET | `/api/audit` | да | нет | нет | журнал (`?limit=`, `?domain=`) |
| GET | `/api/tokens` | да | нет | нет | выпущенные токены (префикс и метаданные) |
| POST | `/api/tokens` | да | да | да | выпустить токен |
| DELETE | `/api/tokens/{id}` | да | да | да | отозвать токен |

## Примеры

```bash
# прочитать зону токеном
curl -s -H "Authorization: Bearer $TOKEN" https://panel.example.com/api/domains/example.com/zone

# план шага пресета — ничего не пишет
curl -s -X POST -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
     -d '{"params":{"dkim":"v=DKIM1; k=rsa; p=MIIB…"}}' \
     'https://panel.example.com/api/domains/example.com/preset/google-workspace/preview?step=dkim'

# применить: шаг DKIM требует имя домена как подтверждение
curl -s -X POST -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
     -d '{"params":{"dkim":"v=DKIM1; k=rsa; p=MIIB…"},"confirm":"example.com"}' \
     'https://panel.example.com/api/domains/example.com/preset/google-workspace/apply?step=dkim'
```

`step` можно передать и в теле, и в query — тело важнее. `dry_run: true` в теле принимают
пишущие маршруты: ответ содержит только план, это и есть способ «посмотреть, что будет».

## Чего в ответах нет

Ключа API Fornex, хэша пароля, значения выпущенного токена после момента выпуска и значений
секретов. `GET /api/tokens` показывает префикс и метаданные; полный токен появляется один раз —
в ответе на `POST /api/tokens`.
