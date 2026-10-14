<?php
// SzenzorKozpont — PDO connect, migration, query helpers.

declare(strict_types=1);

function cfg(): array
{
    static $c = null;
    if ($c === null) {
        $f = __DIR__ . '/../config.php';
        $c = is_file($f) ? require $f : require __DIR__ . '/../config.example.php';
        date_default_timezone_set($c['timezone'] ?? 'UTC');
    }
    return $c;
}

function db_driver(): string
{
    $d = cfg()['db_driver'] ?? 'sqlite';
    return $d === 'mysql' ? 'mysql' : 'sqlite';
}

/** Key-value setting (settings table). Default on missing table/key. */
function setting(string $key, ?string $default = null): ?string
{
    try {
        $r = q1('SELECT v FROM settings WHERE k=?', [$key]);
    } catch (PDOException $e) {
        return $default;
    }
    if ($r === null) {
        return $default;
    }
    return $r['v'] ?? $default;
}

function setting_set(string $key, ?string $value): void
{
    if (db_driver() === 'mysql') {
        q('INSERT INTO settings (k, v) VALUES (?,?)
           ON DUPLICATE KEY UPDATE v=VALUES(v)', [$key, $value]);
    } else {
        q('INSERT INTO settings (k, v) VALUES (?,?)
           ON CONFLICT(k) DO UPDATE SET v=excluded.v', [$key, $value]);
    }
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = cfg();
        if (db_driver() === 'mysql') {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=utf8mb4',
                $c['mysql_host'],
                $c['mysql_db']
            );
            $pdo = new PDO($dsn, $c['mysql_user'], $c['mysql_pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } else {
            $path = $c['sqlite_path'];
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0770, true);
            }
            $pdo = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $pdo->exec('PRAGMA journal_mode=WAL');
            $pdo->exec('PRAGMA foreign_keys=OFF');
        }
    }
    return $pdo;
}

/** Schema version: ALWAYS bump when schema.php or the ALTER list below changes!
 *  In flag filename, so first hit after an SFTP code update always
 *  runs migration (plain timestamp would not guarantee it:
 *  a fresh flag from an old migration would skip new columns). */
const SCHEMA_VERSION = 9; // 9: alarm_rules.repeat -> rep (MySQL reserved word!) (2026-10)

/** Migration flag file (DB-dependent — see szk_migrate_throttled). */
function migrate_flag_file(): string
{
    // Flag file is DB-dependent: several DBs may share one data_dir
    // (test/staging) — a shared flag would skip migration.
    $id = db_driver() === 'mysql'
        ? (string)(cfg()['mysql_host'] ?? '') . '/' . (string)(cfg()['mysql_db'] ?? '')
        : (string)(cfg()['sqlite_path'] ?? '');
    return rtrim(cfg()['data_dir'], '/') . '/.migrate_ts_v' . SCHEMA_VERSION
        . '_' . substr(sha1($id), 0, 8);
}

/** Manual migration (Settings button): returns error list (empty = clean).
 * Throws on severe error (e.g. DB cannot be opened). */
function migrate_now(): array
{
    $errs = [];
    migrate($errs);
    if (empty($errs)) {
        @touch(migrate_flag_file());
    }
    return $errs;
}

/** Throttled migration for web requests: runs at most hourly,
 *  but immediately on new schema version. So first API call after
 *  an SFTP code update heals schema alone (no manual install/cron
 *  run needed). Cheap: usually 1 stat(). */
function szk_migrate_throttled(): void
{
    try {
        $f = migrate_flag_file();
        $last = is_file($f) ? (int)@filemtime($f) : 0;
        if (now_ts() - $last < 3600) {
            return;
        }
        if (migrate()) {
            @touch($f);
        }
    } catch (Throwable $e) {
        error_log('SzenzorKozpont migrate: ' . $e->getMessage());
    }
}
function migrate(?array &$errors = null): bool
{
    $errs = [];
    require_once __DIR__ . '/schema.php';
    $pdo = db();
    $dup = static function (string $msg): bool {
        // Existing object (table/column/index): not an error, just noise.
        foreach (['Duplicate key name', 'already exists', 'duplicate column',
                  'Duplicate column', 'duplicate key'] as $s) {
            if (stripos($msg, $s) !== false) {
                return true;
            }
        }
        return false;
    };
    foreach (schema_statements(db_driver()) as $sql) {
        try {
            $pdo->exec($sql);
        } catch (PDOException $e) {
            // Best-effort: one bad statement must not block the rest
            // (e.g. driver-specific syntax) — but log for diagnostics.
            if (!$dup($e->getMessage())) {
                $errs[] = $e->getMessage() . ' [' . substr($sql, 0, 80) . ']';
                error_log('SzenzorKozpont migrate: ' . end($errs));
            }
        }
    }
    // Later-added columns (best-effort; runs on existing DBs too).
    $alter = [
        'ALTER TABLE sensors ADD COLUMN descr TEXT NULL',
        'ALTER TABLE clients ADD COLUMN ota_last_check INT NOT NULL DEFAULT 0',
        'ALTER TABLE clients ADD COLUMN inactivity_mult INT NOT NULL DEFAULT 3',
        'ALTER TABLE clients ADD COLUMN notify_mode VARCHAR(10) NOT NULL DEFAULT \'none\'',
        'ALTER TABLE clients ADD COLUMN notify_target TEXT NULL',
        'ALTER TABLE sysinfo ADD COLUMN variant VARCHAR(32) NOT NULL DEFAULT \'\'',
        'ALTER TABLE ota_releases ADD COLUMN variant VARCHAR(32) NULL',
        'ALTER TABLE sensors ADD COLUMN ctl_json TEXT NULL',
        'ALTER TABLE sensors ADD COLUMN last_extra TEXT NULL',
        'ALTER TABLE clients ADD COLUMN debug INT NOT NULL DEFAULT 0',
        'CREATE TABLE IF NOT EXISTS cron_state (job VARCHAR(32) NOT NULL PRIMARY KEY, last_run INT NOT NULL DEFAULT 0)',
        'ALTER TABLE users ADD COLUMN email TEXT NULL',
        'ALTER TABLE users ADD COLUMN protected INT NOT NULL DEFAULT 0',
        'CREATE TABLE IF NOT EXISTS settings (k VARCHAR(64) NOT NULL PRIMARY KEY, v TEXT NULL)',
        'ALTER TABLE alarms ADD COLUMN rule_id INT NULL',
    ];
    foreach ($alter as $sql) {
        try {
            $pdo->exec($sql);
        } catch (PDOException $e) {
            if (!$dup($e->getMessage())) {
                $errs[] = $e->getMessage() . ' [' . substr($sql, 0, 80) . ']';
                error_log('SzenzorKozpont migrate: ' . end($errs));
            }
        }
    }
    // Rename legacy `repeat` column (only where it really exists —
    // SQLite DBs built with faulty v8 code; on MySQL such a column
    // could never exist). Conditional, safe on fresh installs.
    try {
        if (db_driver() === 'mysql') {
            $cols = array_column(qall('SELECT column_name name FROM information_schema.columns
                WHERE table_schema=DATABASE() AND table_name=?', ['alarm_rules']), 'name');
        } else {
            $cols = array_column(qall('PRAGMA table_info("alarm_rules")'), 'name');
        }
        if (in_array('repeat', $cols, true) && !in_array('rep', $cols, true)) {
            q('ALTER TABLE alarm_rules RENAME COLUMN repeat TO rep');
        }
    } catch (PDOException $e) {
        $errs[] = $e->getMessage() . ' [RENAME repeat->rep]';
        error_log('SzenzorKozpont migrate: ' . end($errs));
    }
    // Protect earliest (install-time) admin — one-shot:
    // if a protected admin exists, leave it (idempotent, safe).
    try {
        q('UPDATE users SET protected=1 WHERE role=? AND NOT EXISTS
            (SELECT 1 FROM users WHERE protected<>0)
            AND id=(SELECT MIN(id) FROM users WHERE role=?)',
            ['admin', 'admin']);
    } catch (PDOException $e) {
        // E.g. no users table or protected column yet.
        $errs[] = $e->getMessage();
    }
    // Record applied version: file flag is not enough, as data_dir
    // is not always writable (flag may never appear, yet migration
    // may still run) — System box reads from this too.
    if (empty($errs)) {
        try {
            setting_set('schema_version', (string)SCHEMA_VERSION);
        } catch (PDOException $e) {
            // No settings table yet: not an error, retry next time.
        }
    }
    if ($errors !== null) {
        $errors = $errs;
    }
    return empty($errs);
}

function q(string $sql, array $p = []): PDOStatement
{
    $s = db()->prepare($sql);
    $s->execute($p);
    return $s;
}

function q1(string $sql, array $p = []): ?array
{
    $r = q($sql, $p)->fetch();
    return $r === false ? null : $r;
}

function qall(string $sql, array $p = []): array
{
    return q($sql, $p)->fetchAll();
}

function last_id(): int
{
    return (int)db()->lastInsertId();
}

/** Data dir (OTA files). */
function data_dir(): string
{
    $d = cfg()['data_dir'];
    if (!is_dir($d)) {
        mkdir($d, 0770, true);
    }
    foreach (['ota', 'configs'] as $sub) {
        $o = $d . '/' . $sub;
        if (!is_dir($o)) {
            mkdir($o, 0770, true);
        }
    }
    return $d;
}

/** Save last raw client packet (debug: full config/report body).
 *  Writes to disk only for debug-mode clients (too much I/O live). */
function raw_log(int $clientId, string $kind, string $body): void
{
    if ($body === '' || strlen($body) > 524288) {
        return;
    }
    try {
        $c = q1('SELECT debug FROM clients WHERE id=?', [$clientId]);
    } catch (PDOException $e) {
        return;
    }
    if (!$c || (int)$c['debug'] !== 1) {
        return;
    }
    @file_put_contents(data_dir() . '/configs/client_' . $clientId . '_' . $kind . '.json', $body);
}

function audit(string $actor, string $action, string $detail = ''): void
{
    q('INSERT INTO audit_log (ts, actor, action, detail, ip) VALUES (?,?,?,?,?)',
        [now_ts(), $actor, $action, $detail, client_ip()]);
}
