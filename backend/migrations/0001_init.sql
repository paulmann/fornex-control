-- Схема локального состояния панели Fornex Control.
-- Источник истины по DNS — API Fornex; здесь только журнал, токены и снимки.

CREATE TABLE IF NOT EXISTS audit_events (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    created_at  TEXT    NOT NULL,
    actor       TEXT    NOT NULL,
    action      TEXT    NOT NULL,
    target      TEXT    NOT NULL DEFAULT '',
    summary     TEXT    NOT NULL DEFAULT '',
    dry_run     INTEGER NOT NULL DEFAULT 0,
    status      TEXT    NOT NULL DEFAULT 'ok',
    request_id  TEXT    NOT NULL DEFAULT '',
    payload     TEXT    NOT NULL DEFAULT '{}'
);

CREATE INDEX IF NOT EXISTS idx_audit_created ON audit_events (created_at DESC);
CREATE INDEX IF NOT EXISTS idx_audit_target ON audit_events (target);

CREATE TABLE IF NOT EXISTS snapshots (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    created_at   TEXT    NOT NULL,
    domain       TEXT    NOT NULL,
    kind         TEXT    NOT NULL,
    path         TEXT    NOT NULL,
    sha256       TEXT    NOT NULL,
    entries      INTEGER NOT NULL DEFAULT 0,
    note         TEXT    NOT NULL DEFAULT ''
);

CREATE INDEX IF NOT EXISTS idx_snapshots_domain ON snapshots (domain, created_at DESC);

CREATE TABLE IF NOT EXISTS api_tokens (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    created_at   TEXT    NOT NULL,
    name         TEXT    NOT NULL,
    prefix       TEXT    NOT NULL,
    hash         TEXT    NOT NULL UNIQUE,
    write_access INTEGER NOT NULL DEFAULT 0,
    last_used_at TEXT,
    revoked_at   TEXT
);

CREATE TABLE IF NOT EXISTS login_attempts (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    created_at  INTEGER NOT NULL,
    ip          TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_attempts_ip ON login_attempts (ip, created_at);
