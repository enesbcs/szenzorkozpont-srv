<?php
// SzenzorKozpont — DB schema for MySQL and SQLite.
// All timestamps epoch seconds (INT), so no driver-specific date handling.

declare(strict_types=1);

function schema_statements(string $driver): array
{
    $isMysql = ($driver === 'mysql');
    $ai = $isMysql ? 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY'
                   : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $int = $isMysql ? 'INT' : 'INTEGER';
    $uint = $isMysql ? 'INT UNSIGNED' : 'INTEGER';
    $txt = static function (int $n) use ($isMysql): string {
        return $isMysql ? "VARCHAR($n)" : 'TEXT';
    };
    $num = $isMysql ? 'DOUBLE' : 'REAL';
    $eng = $isMysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

    $t = [];

    $t[] = "CREATE TABLE IF NOT EXISTS users ("
        . "id $ai, name {$txt(64)} NOT NULL, pass_hash TEXT NOT NULL, "
        . "role {$txt(10)} NOT NULL DEFAULT 'user', locked_until $int NOT NULL DEFAULT 0, "
        . "email TEXT NULL, protected $int NOT NULL DEFAULT 0, "
        . "created_at $int NOT NULL)$eng";

    $t[] = "CREATE TABLE IF NOT EXISTS clients ("
        . "id $ai, name {$txt(100)} NOT NULL, note TEXT NULL, "
        . "poll_day_s $int NOT NULL DEFAULT 600, poll_night_s $int NOT NULL DEFAULT 1200, "
        . "day_from {$txt(5)} NOT NULL DEFAULT '06:00', day_to {$txt(5)} NOT NULL DEFAULT '21:00', "
        . "config_rev $int NOT NULL DEFAULT 1, inactivity_mult $int NOT NULL DEFAULT 3, "
        . "notify_mode {$txt(10)} NOT NULL DEFAULT 'none', notify_target TEXT NULL, "
        . "ota_last_check $int NOT NULL DEFAULT 0, debug $int NOT NULL DEFAULT 0, created_at $int NOT NULL)$eng";

    $t[] = "CREATE TABLE IF NOT EXISTS api_keys ("
        . "id $ai, client_id $int NOT NULL, prefix {$txt(8)} NOT NULL, "
        . "khash CHAR(64) NOT NULL, created_by $int NULL, created_at $int NOT NULL, "
        . "revoked_at $int NULL, last_used_at $int NULL, note TEXT NULL)$eng";

    $t[] = "CREATE TABLE IF NOT EXISTS sensors ("
        . "id $ai, client_id $int NOT NULL, sensor_key {$txt(50)} NOT NULL, "
        . "label {$txt(100)} NOT NULL DEFAULT '', descr TEXT NULL, "
        . "unit {$txt(24)} NOT NULL DEFAULT '', slope $num NOT NULL DEFAULT 1, "
        . "offset_ $num NOT NULL DEFAULT 0, decimals $int NOT NULL DEFAULT 1, "
        . "store_history $int NOT NULL DEFAULT 1, alm_min $num NULL, alm_max $num NULL, "
        . "kind {$txt(10)} NOT NULL DEFAULT 'telemetry', dtype {$txt(10)} NOT NULL DEFAULT 'float', "
        . "group_key {$txt(50)} NULL, group_label {$txt(100)} NULL, sort_no $int NOT NULL DEFAULT 0, "
        . "ctl_json TEXT NULL, "
        . "collect {$txt(10)} NOT NULL DEFAULT 'pending', expected_every_s $int NULL, "
        . "first_seen $int NOT NULL, last_seen $int NULL, "
        . "last_v_num $num NULL, last_v_str TEXT NULL, last_v_bool $int NULL, "
        . "last_extra TEXT NULL, "
        . "last_ts_client $int NULL, last_ts_server $int NULL)$eng";

    $t[] = "CREATE TABLE IF NOT EXISTS sensor_attrs ("
        . "sensor_id $int NOT NULL, name {$txt(50)} NOT NULL, atype {$txt(10)} NOT NULL DEFAULT 'TEXT', "
        . "value_ {$txt(100)} NOT NULL DEFAULT '', updated_at $int NOT NULL, "
        . "PRIMARY KEY (sensor_id, name))$eng";

    $t[] = "CREATE TABLE IF NOT EXISTS client_acl ("
        . "user_id $int NOT NULL, client_id $int NOT NULL, "
        . "PRIMARY KEY (user_id, client_id))$eng";

    $t[] = "CREATE TABLE IF NOT EXISTS sensor_acl ("
        . "user_id $int NOT NULL, sensor_id $int NOT NULL, "
        . "can_view $int NOT NULL DEFAULT 1, can_control $int NOT NULL DEFAULT 0, "
        . "PRIMARY KEY (user_id, sensor_id))$eng";

    $t[] = "CREATE TABLE IF NOT EXISTS group_acl ("
        . "user_id $int NOT NULL, client_id $int NOT NULL, group_key {$txt(50)} NOT NULL, "
        . "can_view $int NOT NULL DEFAULT 1, can_control $int NOT NULL DEFAULT 0, "
        . "PRIMARY KEY (user_id, client_id, group_key))$eng";

    $t[] = "CREATE TABLE IF NOT EXISTS readings_raw ("
        . "id $ai, client_id $int NOT NULL, sensor_id $int NOT NULL, "
        . "ts_client $int NOT NULL, ts_server $int NOT NULL, "
        . "v_num $num NULL, v_str TEXT NULL, v_bool $int NULL)$eng";

    $t[] = "CREATE TABLE IF NOT EXISTS readings_hourly ("
        . "sensor_id $int NOT NULL, hour_ts $int NOT NULL, hmin $num NOT NULL, "
        . "hmax $num NOT NULL, havg $num NOT NULL, hlast $num NOT NULL, cnt $int NOT NULL, "
        . "PRIMARY KEY (sensor_id, hour_ts))$eng";

    $t[] = "CREATE TABLE IF NOT EXISTS commands ("
        . "id $ai, client_id $int NOT NULL, type {$txt(20)} NOT NULL, payload TEXT NOT NULL DEFAULT '{}', "
        . "target_n {$txt(50)} NULL, created_at $int NOT NULL, delivered_at $int NULL, "
        . "acked_at $int NULL, status {$txt(12)} NOT NULL DEFAULT 'queued')$eng";

    $t[] = "CREATE TABLE IF NOT EXISTS alarms ("
        . "id $ai, client_id $int NOT NULL, sensor_id $int NULL, type {$txt(12)} NOT NULL, "
        . "state {$txt(10)} NOT NULL DEFAULT 'active', value_ $num NULL, threshold $num NULL, "
        . "created_at $int NOT NULL, cleared_at $int NULL, notified_at $int NULL)$eng";

    $t[] = "CREATE TABLE IF NOT EXISTS sysinfo ("
        . "client_id $int NOT NULL PRIMARY KEY, ts $int NOT NULL, cpu TEXT NULL, "
        . "runtime TEXT NULL, storage_free $int NULL, fw_v {$txt(32)} NOT NULL DEFAULT '', "
        . "variant {$txt(32)} NOT NULL DEFAULT '', "
        . "extra TEXT NULL, net_metered $int NOT NULL DEFAULT 0)$eng";

    // Client-REPORTED trigger list (request_triggers →
    // POST /api/v1/triggers): which binary sensor changes it watches now.
    // Only last state stored (matrix page works from it).
    $t[] = "CREATE TABLE IF NOT EXISTS triggers_state ("
        . "client_id $int NOT NULL PRIMARY KEY, triggers_json TEXT NOT NULL DEFAULT '[]', "
        . "updated_at $int NOT NULL DEFAULT 0)$eng";

    $t[] = "CREATE TABLE IF NOT EXISTS ota_releases ("
        . "id $ai, client_type {$txt(10)} NOT NULL, version {$txt(32)} NOT NULL, "
        . "min_from {$txt(32)} NULL, variant {$txt(32)} NULL, url_path TEXT NOT NULL, size_bytes $int NOT NULL DEFAULT 0, "
        . "channel {$txt(10)} NOT NULL DEFAULT 'stable', created_at $int NOT NULL)$eng";

    $t[] = "CREATE TABLE IF NOT EXISTS auth_failures ("
        . "id $ai, ip {$txt(45)} NOT NULL, key_prefix {$txt(16)} NULL, user_ {$txt(64)} NULL, "
        . "ts $int NOT NULL)$eng";

    $t[] = "CREATE TABLE IF NOT EXISTS audit_log ("
        . "id $ai, ts $int NOT NULL, actor {$txt(64)} NOT NULL, action {$txt(64)} NOT NULL, "
        . "detail TEXT NULL, ip {$txt(45)} NOT NULL DEFAULT '')$eng";

    $t[] = "CREATE TABLE IF NOT EXISTS rate_hits ("
        . "ip {$txt(45)} NOT NULL, k {$txt(64)} NOT NULL, ts $int NOT NULL)$eng";

    $t[] = "CREATE TABLE IF NOT EXISTS mtokens ("
        . "id $ai, user_id $int NOT NULL, kind {$txt(10)} NOT NULL, thash CHAR(64) NOT NULL, "
        . "expires_at $int NOT NULL, created_at $int NOT NULL)$eng";

    $t[] = "CREATE TABLE IF NOT EXISTS cron_state ("
        . "job {$txt(32)} NOT NULL PRIMARY KEY, last_run $int NOT NULL DEFAULT 0)$eng";

    // Key-value settings (SMTP, retention override, mail interval…).
    $t[] = "CREATE TABLE IF NOT EXISTS settings ("
        . "k {$txt(64)} NOT NULL PRIMARY KEY, v TEXT NULL)$eng";

    // Mobile user tokens: exactly one row per user (regeneration
    // overwrites). Hash-only storage — name/password not recoverable.
    $t[] = "CREATE TABLE IF NOT EXISTS user_tokens ("
        . "user_id $int NOT NULL PRIMARY KEY, prefix {$txt(8)} NOT NULL, "
        . "thash CHAR(64) NOT NULL, created_at $int NOT NULL)$eng";

    // Alarm rules (admin) + sensor assignments.
    $t[] = "CREATE TABLE IF NOT EXISTS alarm_rules ("
        . "id $ai, name {$txt(100)} NOT NULL, kind {$txt(10)} NOT NULL, "
        . "threshold $num NULL, hours $num NULL, target_state {$txt(8)} NULL, "
        . "rep {$txt(8)} NOT NULL DEFAULT 'daily', "
        . "notify_mode {$txt(8)} NOT NULL DEFAULT 'log', "
        . "notify_target TEXT NULL, created_by $int NULL, "
        . "enabled $int NOT NULL DEFAULT 1, created_at $int NOT NULL)$eng";
    $t[] = "CREATE TABLE IF NOT EXISTS alarm_rule_sensors ("
        . "rule_id $int NOT NULL, sensor_id $int NOT NULL, "
        . "PRIMARY KEY (rule_id, sensor_id))$eng";

    // Indexes (separate, as SQLite dislikes inline INDEX)
    $idx = [
        "CREATE INDEX IF NOT EXISTS ix_keys_prefix ON api_keys (prefix)",
        "CREATE INDEX IF NOT EXISTS ix_keys_client ON api_keys (client_id)",
        "CREATE INDEX IF NOT EXISTS ix_sensors_client ON sensors (client_id)",
        "CREATE UNIQUE INDEX IF NOT EXISTS ux_sensors_key ON sensors (client_id, sensor_key)",
        "CREATE UNIQUE INDEX IF NOT EXISTS ux_readings ON readings_raw (sensor_id, ts_client)",
        "CREATE INDEX IF NOT EXISTS ix_readings_srv ON readings_raw (sensor_id, ts_server)",
        "CREATE INDEX IF NOT EXISTS ix_readings_client ON readings_raw (client_id, ts_server)",
        "CREATE INDEX IF NOT EXISTS ix_commands ON commands (client_id, status)",
        "CREATE INDEX IF NOT EXISTS ix_alarms ON alarms (client_id, state)",
        "CREATE INDEX IF NOT EXISTS ix_alarms_sensor ON alarms (sensor_id, state)",
        "CREATE INDEX IF NOT EXISTS ix_fails ON auth_failures (ip, ts)",
        "CREATE INDEX IF NOT EXISTS ix_rate ON rate_hits (k, ts)",
        "CREATE UNIQUE INDEX IF NOT EXISTS ux_ota ON ota_releases (client_type, version)",
        "CREATE UNIQUE INDEX IF NOT EXISTS ux_mtokens ON mtokens (thash)",
        "CREATE INDEX IF NOT EXISTS ix_audit ON audit_log (ts)",
    ];
    // MySQL lacks "IF NOT EXISTS" for indexes: plain CREATE INDEX there,
    // swallow error (fresh installs have an empty DB anyway).
    if ($isMysql) {
        foreach ($idx as &$s) {
            $s = str_replace('CREATE UNIQUE INDEX IF NOT EXISTS', 'CREATE UNIQUE INDEX', $s);
            $s = str_replace('CREATE INDEX IF NOT EXISTS', 'CREATE INDEX', $s);
        }
        unset($s);
    }

    return array_merge($t, $idx);
}
