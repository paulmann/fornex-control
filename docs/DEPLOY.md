# Deployment

## What you need

- PHP 8.5 (FPM preferred) with `curl`, `json`, `mbstring`, `openssl`, `pdo_sqlite`.
- nginx or any server that can pass `.php` files to FPM and serve `assets/` directly.
- A Fornex API key.
- A directory for state that the web server can write and HTTP cannot read.

## Layout

```text
/srv/fornex-control/          application (readable by the PHP user)
/srv/fornex.env               configuration (chmod 640, owner = PHP user, outside the web root)
/srv/fornex-control/var/      database, log, snapshots (writable, not served)
```

The configuration file may also live inside the application directory as `.env`; the
recommended place is one level above it, so a misconfigured `location` cannot serve it.

## Steps

```bash
# 1. code
git clone https://github.com/paulmann/fornex-control.git /srv/fornex-control
chown -R www-data:www-data /srv/fornex-control

# 2. configuration
install -m 640 -o www-data -g www-data /srv/fornex-control/.env.example /srv/fornex.env
$EDITOR /srv/fornex.env

# 3. state
install -d -m 750 -o www-data -g www-data \
    /srv/fornex-control/var /srv/fornex-control/var/log /srv/fornex-control/var/snapshots

# 4. checks before exposing anything
sudo -u www-data php /srv/fornex-control/backend/bin/fornex help
sudo -u www-data php /srv/fornex-control/tests/plan_test.php
sudo -u www-data php /srv/fornex-control/tools/lint.php
```

`php backend/bin/fornex help` is the cheapest proof that the configuration is readable, the
key is present, the database is writable and the migrations have run.

## nginx

Application in its own directory, served as a sub-path `/fornex/`:

```nginx
location ^~ /fornex/ {
    alias /srv/fornex-control/;
    index index.php;

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.5-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $request_filename;
    }

    # never served: application internals and state
    location ~ ^/fornex/(backend|tests|tools|var)/ { deny all; }
    location ~ /\. { deny all; }
}
```

`APP_BASE=/fornex` must match the sub-path, otherwise the interface requests `/api/…`
at the site root.

Application in the site root instead:

```nginx
root /srv/fornex-control;
index index.php;
location / { try_files $uri $uri/ /index.php?$query_string; }
location ~ \.php$ { include fastcgi_params; fastcgi_pass unix:/run/php/php8.5-fpm.sock;
                    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name; }
location ~ ^/(backend|tests|tools|var)/ { deny all; }
location ~ /\. { deny all; }
```

## Access control

The panel is an administrative surface with a DNS API key behind it. Restrict it by address
unless it is deliberately public:

```nginx
allow 192.0.2.0/24;      # your network
allow 2001:db8::/32;     # if you use IPv6
deny all;
```

`X-Forwarded-For` is honoured **only when the connection arrives from a loopback address**
(nginx on the same host): in that setup the real client address exists nowhere else. From any
other source the header is ignored, so `AUTO_LOGIN_IP` and the login rate limit cannot be
spoofed. If you put a proxy on another machine, move it to the same host or accept that every
request will look like the proxy's address.

## TLS and headers

```nginx
add_header Strict-Transport-Security "max-age=31536000" always;
add_header X-Content-Type-Options "nosniff" always;
add_header X-Frame-Options "DENY" always;
add_header Referrer-Policy "no-referrer" always;
```

The SPA shell already sends `<meta name="robots" content="noindex, nofollow">` and
`Cache-Control: no-store` for API responses.

## After a deploy

```bash
sudo -u www-data php /srv/fornex-control/tools/lint.php
curl -s https://panel.example.com/fornex/api/health
```

`/api/health` answers `{"ok":true,"status":"ok","version":"…","php":"8.5.x"}`, requires no
authentication and exposes nothing but the version — that is what a monitor should poll.

If you changed `assets/js/app.js` or CSS, bump `APP_VERSION`: static links carry `?v=<version>`,
and that is what makes a browser fetch the new file instead of the cached one.

## Backup

What is worth backing up:

| Path | Why |
|---|---|
| `/srv/fornex.env` | the only copy of the API key and the password hash |
| `var/fornex.sqlite` | audit log, issued tokens, snapshot index |
| `var/snapshots/` | zone snapshots, one directory per domain |

The zones themselves are not in the backup — they live in Fornex. `sqlite3 var/fornex.sqlite
"VACUUM INTO '/backup/fornex-$(date +%F).sqlite'"` gives a consistent copy while the panel
is running.

## Updating

```bash
cd /srv/fornex-control
git pull --ff-only
sudo -u www-data php tools/lint.php
sudo -u www-data php tests/plan_test.php
systemctl reload php8.5-fpm      # opcache picks up the new code
```

Migrations run on the first request after the update; they are additive (`CREATE TABLE IF NOT
EXISTS`), so an older database keeps working during the reload.

## Removing

```bash
rm -rf /srv/fornex-control /srv/fornex.env
```

Remove the nginx block as well. Snapshots and the audit log disappear with the directory —
export them first if they are evidence you need to keep.

## Running without a web server

For a look at the interface, a demonstration or a test:

```bash
cp .env.example .env
php -S 127.0.0.1:8080 index.php
```

`index.php` recognises the `cli-server` SAPI and returns `false` for existing static files, so
CSS and JavaScript are served without going through the router. This mode is single-threaded
and not for production.
