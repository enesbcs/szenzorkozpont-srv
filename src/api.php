<?php
// SzenzorKozpont — IoT API endpoints: pull / report / config / sysinfo / ota.

declare(strict_types=1);

const CMD_TYPES = ['request_config', 'request_sysinfo', 'set_poll', 'control', 'ota_notice', 'ota_update',
    'set_triggers', 'request_triggers'];
const MAX_BODY = 16384; // report + pull + sysinfo + ota/check: frequent, tiny
const MAX_CONFIG_BODY = 524288; // config: rare, but can be large on HA side (registry)
const MAX_ENTRIES = 200;
const MAX_CONFIG_ITEMS = 200;

/** Enqueue command; merge control (keep last only). */
function enqueue_command(int $clientId, string $type, array $arg = [], ?string $targetN = null): int
{
    // One-shot state queries / full-replace commands: never more than
    // one active per client — keep LAST only (delete older queued/sent).
    if (in_array($type, ['request_sysinfo', 'request_triggers', 'set_triggers'], true)) {
        q("DELETE FROM commands WHERE client_id=? AND type=?
           AND status IN ('queued','sent')", [$clientId, $type]);
    }
    if ($type === 'control' && $targetN !== null) {
        // Per sensor keep LAST command only: new overwrites old
        // (old rows deleted, not marked 'superseded').
        q("DELETE FROM commands WHERE client_id=? AND type='control'
           AND target_n=? AND status IN ('queued','sent')", [$clientId, $targetN]);
    }
    q('INSERT INTO commands (client_id, type, payload, target_n, created_at, status)
       VALUES (?,?,?,?,?,?)',
        [$clientId, $type, json_encode($arg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
         $targetN, now_ts(), 'queued']);
    return last_id();
}

function bump_rev(int $clientId): void
{
    q('UPDATE clients SET config_rev=config_rev+1 WHERE id=?', [$clientId]);
}

/**
 * Auto sysinfo request — ONLY if client never sent sysinfo yet.
 * Request-driven changes (polling) get a separate explicit
 * sysinfo request (act_poll / set_poll command).
 */
function ensure_request_sysinfo(array $client): void
{
    $r = q1('SELECT COUNT(*) c FROM sysinfo WHERE client_id=?', [(int)$client['id']]);
    if ((int)($r['c'] ?? 0) > 0) {
        return;
    }
    enqueue_command((int)$client['id'], 'request_sysinfo');
}

/** request_config command if client rev is stale or never seen. */
function ensure_request_config(array $client, int $cfg): void
{
    $need = ((int)$client['config_rev'] !== $cfg);
    if (!$need) {
        $r = q1('SELECT COUNT(*) c FROM sensors WHERE client_id=?', [$client['id']]);
        $need = ((int)($r['c'] ?? 0)) === 0;
    }
    if (!$need) {
        return;
    }
    // Only fresh requests block: queued/sent older than 24h no longer
    // blocks a new one (else a stuck request would suppress config
    // re-requests forever while client spins on rev mismatch).
    $p = q1("SELECT id FROM commands WHERE client_id=? AND type='request_config'
             AND status IN ('queued','sent') AND created_at>?
             LIMIT 1", [$client['id'], now_ts() - 86400]);
    if (!$p) {
        enqueue_command((int)$client['id'], 'request_config');
    }
}

function default_expected(array $client): int
{
    return max((int)$client['poll_day_s'], (int)$client['poll_night_s']);
}

// ---------------------------------------------------------------- pull ---

function api_pull(array $client, array $key, ?array $body): void
{
    key_rate_limit($client, $key);
    if ($body === null) {
        json_err('bad json');
    }
    ensure_request_config($client, (int)($body['cfg'] ?? 0));
    ensure_request_sysinfo($client);

    $rows = qall("SELECT * FROM commands WHERE client_id=? AND status IN ('queued','sent')
                  AND created_at>? ORDER BY id", [$client['id'], now_ts() - 86400]);
    $now = now_ts();
    $cmds = [];
    foreach ($rows as $r) {
        if ($r['type'] === 'control' && $r['status'] === 'queued') {
            // Control command: client deletes row on pickup (pull) —
            // at most one row per sensor anyway (see enqueue_command).
            q('DELETE FROM commands WHERE id=?', [$r['id']]);
        } elseif ($r['status'] === 'queued') {
            q('UPDATE commands SET status=?, delivered_at=? WHERE id=?', ['sent', $now, $r['id']]);
        }
        $arg = json_decode($r['payload'], true);
        $cmds[] = ['id' => (int)$r['id'], 'type' => $r['type'], 'arg' => is_array($arg) ? $arg : []];
    }
    $rev = q1('SELECT config_rev FROM clients WHERE id=?', [$client['id']]);
    // cli= client-sent rev (diagnostic: matches server or not —
    // on mismatch server issued request_config).
    audit('client:' . $client['name'], 'api_pull',
        'cmds=' . count($cmds) . ' cfg=' . (int)($rev['config_rev'] ?? 1)
        . ' cli=' . (int)($body['cfg'] ?? 0));
    json_out(['ok' => true, 'srv_ts' => $now,
        'cfg_rev' => (int)($rev['config_rev'] ?? 1), 'cmds' => $cmds]);
}

// --------------------------------------------------------------- report ---

function resolve_entry(array $en, string $bn): ?array
{
    if (!isset($en['n']) || !is_string($en['n'])) {
        return null;
    }
    $n = $bn . $en['n'];
    if (!valid_key($n)) {
        return null;
    }
    $hasV = array_key_exists('v', $en) && (is_int($en['v']) || is_float($en['v']));
    $hasVs = array_key_exists('vs', $en) && is_string($en['vs']);
    $hasVb = array_key_exists('vb', $en) && is_bool($en['vb']);
    if ((int)$hasV + (int)$hasVs + (int)$hasVb !== 1) {
        return null;
    }
    $t = isset($en['t']) ? (int)$en['t'] : 0;
    if ($t < 0 || $t > 86400) {
        return null;
    }
    return ['n' => $n, 't' => $t,
        'v' => $hasV ? (float)$en['v'] : null,
        'vs' => $hasVs ? $en['vs'] : null,
        'vb' => $hasVb ? $en['vb'] : null];
}

function check_threshold(array $sensor, ?float $vnum): void
{
    if ($vnum === null) {
        return;
    }
    $cal = calibrated($vnum, (float)$sensor['slope'], (float)$sensor['offset_']);
    $low = $sensor['alm_min'] !== null && $cal < (float)$sensor['alm_min'];
    $high = $sensor['alm_max'] !== null && $cal > (float)$sensor['alm_max'];
    $sid = (int)$sensor['id'];
    $cid = (int)$sensor['client_id'];
    if (!$low && !$high) {
        q("UPDATE alarms SET state='cleared', cleared_at=? WHERE sensor_id=? AND state='active'
           AND type IN ('min','max')", [now_ts(), $sid]);
        return;
    }
    $type = $low ? 'min' : 'max';
    $thr = $low ? (float)$sensor['alm_min'] : (float)$sensor['alm_max'];
    $ex = q1("SELECT id FROM alarms WHERE sensor_id=? AND type=? AND state='active' LIMIT 1",
        [$sid, $type]);
    if (!$ex) {
        q('INSERT INTO alarms (client_id, sensor_id, type, state, value_, threshold, created_at)
           VALUES (?,?,?,?,?,?,?)', [$cid, $sid, $type, 'active', $cal, $thr, now_ts()]);
    }
}

function api_report(array $client, array $key, ?array $body): void
{
    key_rate_limit($client, $key);
    quota_check((int)$client['id']);
    if ($body === null) {
        json_err('bad json');
    }
    if (strlen(raw_body()) > MAX_BODY) {
        json_err('too large', 413);
    }
    $now = now_ts();

    // ack handling
    $ack = $body['ack'] ?? [];
    if (is_array($ack)) {
        foreach ($ack as $id) {
            $id = (int)$id;
            if ($id > 0) {
                q("UPDATE commands SET status='acked', acked_at=? WHERE id=? AND client_id=?
                   AND status IN ('queued','sent')", [$now, $id, $client['id']]);
            }
        }
    }

    $bt = (int)($body['bt'] ?? 0);
    if ($bt < $now - 86400 || $bt > $now + 300) {
        json_err('bad time');
    }
    $bn = isset($body['bn']) && is_string($body['bn']) ? $body['bn'] : '';
    if ($bn !== '' && !preg_match('/^[a-zA-Z0-9_.\-]{1,20}$/', $bn)) {
        json_err('bad bn');
    }
    $e = $body['e'] ?? null;
    if (!is_array($e) || count($e) > MAX_ENTRIES) {
        json_err('bad e');
    }

    $cid = (int)$client['id'];
    $stored = 0;
    $dropped = 0;
    // Unchanged keys (change_only client): last_seen refresh
    // without point storage. Always full key name (no bn).
    $same = [];
    if (is_array($body['same'] ?? null)) {
        foreach ($body['same'] as $sk) {
            if (is_string($sk) && valid_key($sk)) {
                $same[] = $sk;
            }
            if (count($same) >= 2000) {
                break;
            }
        }
    }
    // Preprocess entries + collect keys for ONE batched
    // sensor lookup (instead of per-sensor SELECT).
    $res = [];
    $keys = [];
    foreach ($e as $en) {
        if (!is_array($en)) {
            $dropped++;
            continue;
        }
        $r = resolve_entry($en, $bn);
        if ($r === null) {
            $dropped++;
            continue;
        }
        $res[] = [$en, $r];
        $keys[$r['n']] = true;
    }
    foreach ($same as $sk) {
        $keys[$sk] = true;
    }
    $smap = [];
    if ($keys) {
        // Chunked to fit old SQLite variable limit (999).
        foreach (array_chunk(array_keys($keys), 500) as $ckeys) {
            $in = implode(',', array_fill(0, count($ckeys), '?'));
            $par = array_merge([$cid], $ckeys);
            foreach (qall("SELECT * FROM sensors WHERE client_id=? AND sensor_key IN ($in)", $par) as $s) {
                $smap[(string)$s['sensor_key']] = $s;
            }
        }
    }
    $made = [];
    $upd = []; // for rule eval: sid => [v, vb, old_bool] (pre-report row)
    foreach ($res as [$en, $r]) {
        $tAbs = $bt + $r['t'];
        $s = $smap[$r['n']] ?? null;
        if ($s === null) {
            if (isset($made[$r['n']])) {
                $dropped++;
                continue; // already created as pending in same report
            }
            // Unknown -> pending, data DROPPED until approved.
            $dtype = $r['v'] !== null ? 'float' : ($r['vs'] !== null ? 'string' : 'bool');
            q("INSERT INTO sensors (client_id, sensor_key, label, kind, dtype, collect,
                expected_every_s, first_seen) VALUES (?,?,?,?,?,?,?,?)",
                [$cid, $r['n'], $r['n'], 'telemetry', $dtype, 'pending',
                 default_expected($client), $now]);
            $made[$r['n']] = true;
            $dropped++;
            continue;
        }
        if ($s['collect'] !== 'yes') {
            $dropped++;
            continue; // denied / pending -> drop entirely
        }
        $vb = $r['vb'] === null ? null : ($r['vb'] ? 1 : 0);
        // Optional state params (e.g. brightness/color): latest display only,
        // never history (by design). Bad `p` drops param only.
        // Missing `p` KEEPS old last_extra (change_only: `p` sent only
        // on change) — overwrite only on incoming `p`.
        $px = (isset($en['p']) && is_array($en['p'])) ? validate_entry_params($en['p']) : null;
        $pxJson = $px !== null ? json_encode($px) : null;
        q('UPDATE sensors SET last_seen=?, last_v_num=?, last_v_str=?, last_v_bool=?,
           last_extra=COALESCE(?,last_extra), last_ts_client=?, last_ts_server=? WHERE id=?',
            [$tAbs, $r['v'], $r['vs'], $vb, $pxJson, $tAbs, $now, $s['id']]);
        if ((int)$s['store_history'] === 1) {
            if (db_driver() === 'mysql') {
                q('INSERT IGNORE INTO readings_raw (client_id, sensor_id, ts_client, ts_server,
                   v_num, v_str, v_bool) VALUES (?,?,?,?,?,?,?)',
                    [$cid, $s['id'], $tAbs, $now, $r['v'], $r['vs'], $vb]);
            } else {
                q('INSERT OR IGNORE INTO readings_raw (client_id, sensor_id, ts_client, ts_server,
                   v_num, v_str, v_bool) VALUES (?,?,?,?,?,?,?)',
                    [$cid, $s['id'], $tAbs, $now, $r['v'], $r['vs'], $vb]);
            }
        }
        check_threshold($s, $r['v']);
        $upd[(int)$s['id']] = ['v' => $r['v'], 'vb' => $vb,
            'old_bool' => $s['last_v_bool'] !== null ? (int)$s['last_v_bool'] : null];
        $stored++;
    }
    // Unchanged: only timestamps refresh (no point, threshold,
    // history) — keeps offline detection accurate.
    $sameN = 0;
    foreach ($same as $sk) {
        $s = $smap[$sk] ?? null;
        if ($s === null || $s['collect'] !== 'yes') {
            $dropped++;
            continue;
        }
        q('UPDATE sensors SET last_seen=?, last_ts_client=?, last_ts_server=? WHERE id=?',
            [$bt, $bt, $now, (int)$s['id']]);
        $sameN++;
    }
    // User rules (threshold/battery/binary) on touched sensors;
    // stale kind evaluated by tick/cron (silent client).
    // Best-effort: rule errors never break report.
    try {
        rules_evaluate_report($cid, $upd);
    } catch (Throwable $e) {
        error_log('SzenzorKozpont rules: ' . $e->getMessage());
    }
    audit('client:' . $client['name'], 'api_report',
        'e=' . count($e) . ' stored=' . $stored . ' dropped=' . $dropped . ' same=' . $sameN
        . ' bytes=' . strlen(raw_body()));
    raw_log($cid, 'report', raw_body());
    json_out(['ok' => true, 'srv_ts' => $now]);
}

// --------------------------------------------------------------- config ---

function api_config(array $client, array $key, ?array $body): void
{
    rate_limit('cfg:' . $key['prefix'], 20, 3600);
    rate_limit('ip:' . client_ip(), 30, 60);
    if ($body === null) {
        json_err('bad json');
    }
    $items = $body['items'] ?? $body['sensors'] ?? null;
    if (!is_array($items) || count($items) > MAX_CONFIG_ITEMS) {
        json_err('bad items');
    }
    // Chunked upload: part/parts (1-based). Each chunk standalone,
    // order-free, retryable — server stores no partial state,
    // upserts per batch by key. Default: 1/1 (single chunk).
    $parts = isset($body['parts']) ? (int)$body['parts'] : 1;
    $part = isset($body['part']) ? (int)$body['part'] : 1;
    if ($parts < 1 || $parts > 99 || $part < 1 || $part > $parts) {
        json_err('bad part');
    }
    if (strlen(raw_body()) > MAX_CONFIG_BODY) {
        json_err('too large', 413);
    }
    // Anti gzip-bomb: encoded body max 2 MB (memoized, no re-read).
    $encLen = strlen(raw_body_encoded());
    if ($encLen > 2 * 1024 * 1024) {
        json_err('too large', 413);
    }
    $now = now_ts();
    $cid = (int)$client['id'];
    foreach ($items as $it) {
        if (!is_array($it) || !isset($it['n']) || !valid_key($it['n'])) {
            continue;
        }
        $n = $it['n'];
        // Overwrite only provided fields (client may send partial
        // config; missing is NOT deletion).
        $label = isset($it['label']) && is_string($it['label']) && $it['label'] !== ''
            ? mb_sub($it['label']) : null;
        $unit = isset($it['u']) && is_string($it['u']) ? substr($it['u'], 0, 24) : null;
        $kind = ($it['kind'] ?? null) === 'control' ? 'control'
            : (isset($it['kind']) ? 'telemetry' : null);
        $dtype = isset($it['dtype']) && in_array($it['dtype'], ['float', 'int', 'bool', 'string'], true)
            ? $it['dtype'] : null;
        $gkSet = array_key_exists('group_key', $it);
        $gk = $gkSet && is_string($it['group_key']) && $it['group_key'] !== ''
            && valid_key($it['group_key']) ? $it['group_key'] : null;
        $glSet = array_key_exists('group_label', $it);
        $gl = $glSet && is_string($it['group_label']) && $it['group_label'] !== ''
            ? substr($it['group_label'], 0, 100) : null;
        $deSet = array_key_exists('description', $it);
        $de = $deSet && is_string($it['description']) ? substr($it['description'], 0, 500) : null;
        $ctlSet = array_key_exists('ctl', $it);
        $ctlRaw = $ctlSet ? $it['ctl'] : null;
        // Invalid descriptor does NOT clear existing; explicit null does.
        $ctlValid = ($ctlSet && $ctlRaw !== null) ? validate_ctl($ctlRaw) : null;
        $ctlClear = $ctlSet && $ctlRaw === null;
        $s = q1('SELECT id, collect FROM sensors WHERE client_id=? AND sensor_key=?', [$cid, $n]);
        if ($s) {
            $sets = [];
            $par = [];
            foreach ([['label', $label], ['unit', $unit], ['kind', $kind], ['dtype', $dtype]]
                     as [$col, $val]) {
                if ($val !== null) {
                    $sets[] = "$col=?";
                    $par[] = $val;
                }
            }
            if ($gkSet) {
                $sets[] = 'group_key=?';
                $par[] = $gk;
            }
            if ($glSet) {
                $sets[] = 'group_label=?';
                $par[] = $gl;
            }
            if ($deSet) {
                $sets[] = 'descr=?';
                $par[] = $de ?? '';
            }
            if ($ctlSet && ($ctlValid !== null || $ctlClear)) {
                $sets[] = 'ctl_json=?';
                $par[] = $ctlValid; // null clears descriptor
            }
            if ($sets) {
                $par[] = $s['id'];
                q('UPDATE sensors SET ' . implode(',', $sets) . ' WHERE id=?', $par);
            }
            $sid = (int)$s['id'];
        } else {
            q("INSERT INTO sensors (client_id, sensor_key, label, descr, unit, kind, dtype, group_key,
                group_label, ctl_json, collect, expected_every_s, first_seen)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)",
                [$cid, $n, $label ?? $n, $de ?? '', $unit ?? '', $kind ?? 'telemetry', $dtype ?? 'float',
                 $gk, $gl, $ctlValid, 'pending', default_expected($client), $now]);
            $sid = last_id();
        }
        // Attributes: max 10, max 100 bytes each.
        $attrs = $it['attrs'] ?? [];
        if (is_array($attrs)) {
            $n2 = 0;
            foreach ($attrs as $an => $av) {
                if ($n2 >= 10) {
                    break;
                }
                if (!is_string($an) || !valid_key($an)) {
                    continue;
                }
                $atype = 'TEXT';
                $aval = '';
                if (is_bool($av)) {
                    $atype = 'BOOL';
                    $aval = $av ? 'true' : 'false';
                } elseif (is_int($av) || is_float($av)) {
                    $atype = 'NUMBER';
                    $aval = (string)$av;
                } elseif (is_string($av)) {
                    $aval = $av;
                } else {
                    continue;
                }
                if (strlen($aval) > 100) {
                    $aval = substr($aval, 0, 100);
                }
                if (db_driver() === 'mysql') {
                    q('INSERT INTO sensor_attrs (sensor_id, name, atype, value_, updated_at)
                       VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE atype=VALUES(atype),
                       value_=VALUES(value_), updated_at=VALUES(updated_at)',
                        [$sid, $an, $atype, $aval, $now]);
                } else {
                    q('INSERT INTO sensor_attrs (sensor_id, name, atype, value_, updated_at)
                       VALUES (?,?,?,?,?) ON CONFLICT(sensor_id, name) DO UPDATE SET
                       atype=excluded.atype, value_=excluded.value_, updated_at=excluded.updated_at',
                        [$sid, $an, $atype, $aval, $now]);
                }
                $n2++;
            }
        }
    }
    $allowed = qall("SELECT sensor_key FROM sensors WHERE client_id=? AND collect='yes' ORDER BY sensor_key", [$cid]);
    $denied = qall("SELECT sensor_key FROM sensors WHERE client_id=? AND collect='no' ORDER BY sensor_key", [$cid]);
    $rev = q1('SELECT config_rev FROM clients WHERE id=?', [$cid]);
    audit('client:' . $client['name'], 'api_config',
        'items=' . count($items) . ' part=' . $part . '/' . $parts);
    raw_log($cid, 'config', raw_body());
    json_out(['ok' => true, 'srv_ts' => $now, 'cfg_rev' => (int)($rev['config_rev'] ?? 1),
        'allowed' => array_column($allowed, 'sensor_key'),
        'denied' => array_column($denied, 'sensor_key'),
        'part' => $part, 'parts' => $parts, 'expects_more' => $part < $parts]);
}

// -------------------------------------------------------------- sysinfo ---

/**
 * Take over poll object from sysinfo to server.
 * Returns stored/default poll info for display (or null).
 * Invalid/missing field -> no takeover (server keeps old value).
 */
function poll_takeover(array $client, ?array $poll): ?array
{
    if ($poll === null) {
        return null;
    }
    $clamp = static fn ($v) => max(60, min(86400, (int)$v));
    $ov = [];
    foreach (is_array($poll['override'] ?? null) ? $poll['override'] : [] as $k => $v) {
        if (in_array($k, ['day', 'night'], true)) {
            $ov[$k] = $clamp($v);
        }
    }
    $info = [
        'day_s' => isset($poll['day_s']) ? $clamp($poll['day_s']) : null,
        'night_s' => isset($poll['night_s']) ? $clamp($poll['night_s']) : null,
        'scope' => in_array($poll['scope'] ?? '', ['day', 'night'], true) ? $poll['scope'] : '',
        'interval_s' => isset($poll['interval_s']) ? $clamp($poll['interval_s']) : null,
        'override' => (object)$ov,
    ];
    if ($info['day_s'] === null || $info['night_s'] === null
        || $info['day_s'] <= 0 || $info['night_s'] <= 0) {
        return $info;
    }
    $exp = (int)max($info['day_s'], $info['night_s'],
        $info['interval_s'] ?? 0, ...array_values($ov));
    $prev = q1('SELECT poll_day_s, poll_night_s FROM clients WHERE id=?', [(int)$client['id']]);
    q('UPDATE clients SET poll_day_s=?, poll_night_s=? WHERE id=?',
        [$info['day_s'], $info['night_s'], (int)$client['id']]);
    q('UPDATE sensors SET expected_every_s=? WHERE client_id=?', [$exp, (int)$client['id']]);
    if ($prev && ((int)$prev['poll_day_s'] !== $info['day_s']
        || (int)$prev['poll_night_s'] !== $info['night_s'])) {
        audit('client:' . $client['name'], 'poll_takeover',
            'day ' . (int)$prev['poll_day_s'] . '->' . $info['day_s']
            . ' night ' . (int)$prev['poll_night_s'] . '->' . $info['night_s']
            . ' scope=' . ($info['scope'] ?: '?') . ' iv=' . ($info['interval_s'] ?? '?'));
    }
    return $info;
}

function api_sysinfo(array $client, array $key, ?array $body): void
{
    rate_limit('cfg:' . $key['prefix'], 20, 3600);
    rate_limit('ip:' . client_ip(), 30, 60);
    if ($body === null) {
        json_err('bad json');
    }
    $fw = isset($body['fw_v']) && is_string($body['fw_v']) ? substr($body['fw_v'], 0, 32) : '';
    // Hardware variant (e.g. lora, sim7670, native, default): marks small
    // firmware HW differences so a wrong image never ships.
    // Empty = unset (only variant-free release allowed then).
    $variant = isset($body['variant']) && is_string($body['variant'])
        ? substr($body['variant'], 0, 32) : '';
    // Client ACTUAL polling (decision: server takes it over): offline
    // alarms (expected_every_s), rate-limit base and client list
    // use client-sent values from here on.
    // Storage: HA client sends `storage_free_mb` (MB), ESP32 may send
    // `storage_free` likewise in MB; accept both.
    $storage = null;
    if (isset($body['storage_free']) && is_numeric($body['storage_free'])) {
        $storage = (int)$body['storage_free'];
    } elseif (isset($body['storage_free_mb']) && is_numeric($body['storage_free_mb'])) {
        $storage = (int)$body['storage_free_mb'];
    }
    // Client type (ha/esp32/android): stored for display and OTA
    // targeting — previously visible only at request time.
    $ctype = isset($body['client_type']) && is_string($body['client_type'])
        ? substr($body['client_type'], 0, 16) : '';
    $poll = poll_takeover($client, is_array($body['poll'] ?? null) ? $body['poll'] : null);
    $row = [
        $client['id'], now_ts(),
        isset($body['cpu']) ? substr((string)$body['cpu'], 0, 200) : '',
        isset($body['runtime']) ? substr((string)$body['runtime'], 0, 200) : '',
        $storage,
        $fw, $variant,
        json_encode(['uptime' => $body['uptime_s'] ?? null, 'ha' => $body['ha_v'] ?? null,
            'arch' => $body['cpu_arch'] ?? null, 'poll' => $poll, 'ctype' => $ctype]),
        !empty($body['net_metered']) ? 1 : 0,
    ];
    if (db_driver() === 'mysql') {
        q('INSERT INTO sysinfo (client_id, ts, cpu, runtime, storage_free, fw_v, variant, extra, net_metered)
           VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE ts=VALUES(ts), cpu=VALUES(cpu),
           runtime=VALUES(runtime), storage_free=VALUES(storage_free), fw_v=VALUES(fw_v),
           variant=VALUES(variant),
           extra=VALUES(extra), net_metered=VALUES(net_metered)', $row);
    } else {
        q('INSERT INTO sysinfo (client_id, ts, cpu, runtime, storage_free, fw_v, variant, extra, net_metered)
           VALUES (?,?,?,?,?,?,?,?,?) ON CONFLICT(client_id) DO UPDATE SET ts=excluded.ts,
           cpu=excluded.cpu, runtime=excluded.runtime, storage_free=excluded.storage_free,
           fw_v=excluded.fw_v, variant=excluded.variant,
           extra=excluded.extra, net_metered=excluded.net_metered', $row);
    }
    audit('client:' . $client['name'], 'api_sysinfo',
        'fw=' . $fw . ' var=' . $variant . ' type=' . ($ctype !== '' ? $ctype : '?'));
    json_out(['ok' => true, 'srv_ts' => now_ts()]);
}

/**
 * Client trigger report (for `request_triggers` command).
 * Body: {"triggers": [{"n": "binary_sensor.mozgas"}, ...]} — only client's
 * own allowed bool sensors kept (max 10).
 * Storage: triggers_state (last state + timestamp for matrix page).
 */
function api_triggers(array $client, array $key, ?array $body): void
{
    rate_limit('cfg:' . $key['prefix'], 20, 3600);
    rate_limit('ip:' . client_ip(), 30, 60);
    if ($body === null) {
        json_err('bad json');
    }
    $items = [];
    if (is_array($body['triggers'] ?? null)) {
        foreach ($body['triggers'] as $t) {
            if (is_array($t) && isset($t['n']) && is_string($t['n']) && valid_key($t['n'])) {
                $items[] = ['n' => $t['n']];
                if (count($items) >= 10) {
                    break;
                }
            }
        }
    }
    $keep = [];
    if ($items) {
        $keys = [];
        foreach ($items as $t) {
            $keys[] = $t['n'];
        }
        $in = implode(',', array_fill(0, count($keys), '?'));
        $par = array_merge([(int)$client['id']], $keys);
        foreach (qall("SELECT sensor_key FROM sensors WHERE client_id=? AND collect='yes'
                       AND dtype='bool' AND sensor_key IN ($in)", $par) as $r) {
            $keep[(string)$r['sensor_key']] = true;
        }
    }
    $items = [];
    foreach ($keys ?? [] as $k) {
        if (isset($keep[$k])) {
            $items[] = ['n' => $k];
        }
    }
    $js = json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($js === false) {
        $js = '[]';
    }
    if (db_driver() === 'mysql') {
        q('INSERT INTO triggers_state (client_id, triggers_json, updated_at) VALUES (?,?,?)
           ON DUPLICATE KEY UPDATE triggers_json=VALUES(triggers_json), updated_at=VALUES(updated_at)',
            [(int)$client['id'], $js, now_ts()]);
    } else {
        q('INSERT INTO triggers_state (client_id, triggers_json, updated_at) VALUES (?,?,?)
           ON CONFLICT(client_id) DO UPDATE SET triggers_json=excluded.triggers_json,
           updated_at=excluded.updated_at',
            [(int)$client['id'], $js, now_ts()]);
    }
    audit('client:' . $client['name'], 'api_triggers', count($items) . ' db');
    json_out(['ok' => true, 'srv_ts' => now_ts()]);
}

// ------------------------------------------------------------------ ota ---

/**
 * Best release for fw_v (stable first, then highest version).
 * STRICT variant match: release only for exact-variant client
 * (empty variant only for empty-reporting client). So a generic
 * image never overwrites targeted firmware, and vice versa.
 */
function ota_pick(string $ctype, string $fw, string $variant = ''): ?array
{
    $fw = $fw === '' ? '0.0.0' : $fw;
    $rows = qall('SELECT * FROM ota_releases WHERE client_type=?', [$ctype]);
    $best = null;
    foreach ($rows as $r) {
        $rv = $r['variant'] ?? '';
        if ($rv === null) {
            $rv = '';
        }
        if ($rv !== $variant) {
            continue;
        }
        if (semver_cmp($fw, $r['version']) >= 0) {
            continue;
        }
        if ($r['min_from'] !== null && $r['min_from'] !== ''
            && semver_cmp($fw, $r['min_from']) < 0) {
            continue;
        }
        if ($best === null) {
            $best = $r;
            continue;
        }
        $rs = $r['channel'] === 'stable' ? 1 : 0;
        $bs = $best['channel'] === 'stable' ? 1 : 0;
        if ($rs > $bs || ($rs === $bs && semver_cmp($r['version'], $best['version']) > 0)) {
            $best = $r;
        }
    }
    return $best;
}

function api_ota_check(array $client, array $key, ?array $body): void
{
    rate_limit('ip:' . client_ip(), 30, 60);
    if ($body === null) {
        json_err('bad json');
    }
    // At most every 30 days.
    $c = q1('SELECT ota_last_check FROM clients WHERE id=?', [$client['id']]);
    $last = (int)($c['ota_last_check'] ?? 0);
    if (now_ts() - $last < 30 * 86400 && $last > 0) {
        json_out(['ok' => false, 'err' => 'too soon'], 429,
            ['Retry-After: ' . (30 * 86400 - (now_ts() - $last))]);
    }
    q('UPDATE clients SET ota_last_check=? WHERE id=?', [now_ts(), $client['id']]);
    $ctype = isset($body['client_type']) && is_string($body['client_type'])
        ? substr($body['client_type'], 0, 10) : 'ha';
    $fw = isset($body['fw_v']) && is_string($body['fw_v']) ? $body['fw_v'] : '';
    // Variant: from request if given; else from last sysinfo.
    // Variant: explicit key in request wins (even if empty);
    // if key missing, use last sysinfo value.
    if (array_key_exists('variant', $body) && is_string($body['variant'])) {
        $variant = substr($body['variant'], 0, 32);
    } else {
        $variant = '';
        $si = q1('SELECT variant FROM sysinfo WHERE client_id=?', [$client['id']]);
        if ($si && isset($si['variant']) && is_string($si['variant'])) {
            $variant = substr($si['variant'], 0, 32);
        }
    }
    $rel = ota_pick($ctype, $fw, $variant);
    if (!$rel) {
        audit('client:' . $client['name'], 'api_otacheck', 'update=false');
        json_out(['ok' => true, 'update' => false]);
    }
    audit('client:' . $client['name'], 'api_otacheck',
        'update=true ver=' . $rel['version']);
    json_out(['ok' => true, 'update' => true, 'version' => $rel['version'],
        'url' => '/ota/' . $rel['client_type'] . '/' . $rel['version'],
        'size' => (int)$rel['size_bytes']]);
}

/** GET /ota/<type>/<ver> — valid API key only. */
function api_ota_get(string $type, string $ver): void
{
    [$client, $key] = api_auth();
    rate_limit('ip:' . client_ip(), 30, 60);
    if (!preg_match('/^[a-z0-9]{1,10}$/', $type) || !preg_match('/^[A-Za-z0-9.\-]{1,32}$/', $ver)) {
        json_out(['ok' => false, 'err' => 'not found'], 404);
    }
    $rel = q1('SELECT * FROM ota_releases WHERE client_type=? AND version=?', [$type, $ver]);
    if (!$rel) {
        json_out(['ok' => false, 'err' => 'not found'], 404);
    }
    $f = data_dir() . '/ota/' . $rel['id'] . '.bin';
    if (!is_file($f)) {
        json_out(['ok' => false, 'err' => 'not found'], 404);
    }
    audit('client:' . $client['name'], 'ota_download', $type . '/' . $ver);
    http_response_code(200);
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . filesize($f));
    header('Cache-Control: no-store');
    readfile($f);
    exit;
}

/** mb_*-free byte cut (no mbstring dep). */
function mb_sub(string $s, int $max = 100): string
{
    return strlen($s) > $max ? substr($s, 0, $max) : $s;
}
