<?php
// SzenzorKozpont — scheduled jobs (cron.php and web tick share).

declare(strict_types=1);

// Job → due interval (s).
const TICK_INTERVALS = [
    'alarms' => 300,   // inactivity + notify
    'hourly' => 3600,  // hourly aggregate
    'expire' => 86400, // expired commands/tokens
    'cleanup' => 86400, // 30-day retention delete
    // Mail digest: no setting — when due (tick), but
    // at most once per minute (next request at night, low traffic).
    'mail' => 60,
];

function cron_due(string $job): bool
{
    try {
        $r = q1('SELECT last_run FROM cron_state WHERE job=?', [$job]);
    } catch (PDOException $e) {
        return false; // e.g. not installed yet
    }
    if (!$r) {
        return true;
    }
    $iv = TICK_INTERVALS[$job] ?? 3600;
    return now_ts() - (int)$r['last_run'] >= $iv;
}

function cron_mark(string $job): void
{
    if (db_driver() === 'mysql') {
        q('INSERT INTO cron_state (job, last_run) VALUES (?,?)
           ON DUPLICATE KEY UPDATE last_run=VALUES(last_run)', [$job, now_ts()]);
    } else {
        q('INSERT INTO cron_state (job, last_run) VALUES (?,?)
           ON CONFLICT(job) DO UPDATE SET last_run=excluded.last_run', [$job, now_ts()]);
    }
}

/** Hourly aggregate from raw. Returns touched sensor count. */
function job_hourly(): int
{
    $now = now_ts();
    $from = $now - 48 * 3600;
    $ss = qall("SELECT id FROM sensors WHERE collect='yes' AND store_history=1");
    foreach ($ss as $s) {
        $rows = qall('SELECT (ts_server - (ts_server % 3600)) h, MIN(v_num) mn, MAX(v_num) mx,
                      AVG(v_num) av, COUNT(*) c FROM readings_raw
                      WHERE sensor_id=? AND ts_server>=? AND v_num IS NOT NULL GROUP BY h',
            [$s['id'], $from]);
        foreach ($rows as $r) {
            $last = q1('SELECT v_num v FROM readings_raw WHERE sensor_id=? AND ts_server<? AND v_num IS NOT NULL
                         ORDER BY ts_server DESC LIMIT 1', [$s['id'], (int)$r['h'] + 3600]);
            $vals = [(int)$s['id'], (int)$r['h'], (float)$r['mn'], (float)$r['mx'],
                     (float)$r['av'], (float)($last['v'] ?? $r['mx']), (int)$r['c']];
            if (db_driver() === 'mysql') {
                q('INSERT INTO readings_hourly (sensor_id, hour_ts, hmin, hmax, havg, hlast, cnt)
                   VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE hmin=VALUES(hmin), hmax=VALUES(hmax),
                   havg=VALUES(havg), hlast=VALUES(hlast), cnt=VALUES(cnt)', $vals);
            } else {
                q('INSERT INTO readings_hourly (sensor_id, hour_ts, hmin, hmax, havg, hlast, cnt)
                   VALUES (?,?,?,?,?,?,?) ON CONFLICT(sensor_id, hour_ts) DO UPDATE SET hmin=excluded.hmin,
                   hmax=excluded.hmax, havg=excluded.havg, hlast=excluded.hlast, cnt=excluded.cnt', $vals);
            }
        }
    }
    return count($ss);
}

/** Retention delete (chunked, safe on big tables). Returns [raw, hourly]. */
function job_cleanup(): array
{
    // Retention: UI setting (settings) overrides config.php.
    $days = (int)(setting('retention_days', '') ?: (cfg()['retention_days'] ?? 30));
    $days = min(3650, max(1, $days));
    $cut = now_ts() - $days * 86400;
    $total = 0;
    // Time cap: must not block long when started from a web request; rest
    // continues on next run. Returns [raw, hourly, done].
    $deadline = now_ts() + 20;
    $done = true;
    do {
        if (db_driver() === 'mysql') {
            $n = q('DELETE FROM readings_raw WHERE ts_server<? LIMIT 5000', [$cut])->rowCount();
        } else {
            $n = q('DELETE FROM readings_raw WHERE id IN
                    (SELECT id FROM readings_raw WHERE ts_server<? LIMIT 5000)', [$cut])->rowCount();
        }
        $total += $n;
        if ($n > 0 && now_ts() >= $deadline) {
            $done = false;
            break;
        }
    } while ($n > 0);
    $b = q('DELETE FROM readings_hourly WHERE hour_ts<?', [$cut])->rowCount();
    q('DELETE FROM rate_hits WHERE ts<?', [now_ts() - 7200]);
    q('DELETE FROM auth_failures WHERE ts<?', [now_ts() - 7 * 86400]);
    // Log trim: client/user traffic rows 7 days, admin ops 30 days.
    q("DELETE FROM audit_log WHERE (action LIKE 'api!_%' ESCAPE '!'"
        . " OR action LIKE 'mlogin!_%' ESCAPE '!' OR action LIKE 'control!_%' ESCAPE '!')"
        . ' AND ts<?', [now_ts() - 7 * 86400]);
    q("DELETE FROM audit_log WHERE action NOT LIKE 'api!_%' ESCAPE '!'"
        . " AND action NOT LIKE 'mlogin!_%' ESCAPE '!' AND action NOT LIKE 'control!_%' ESCAPE '!'"
        . ' AND ts<?', [now_ts() - 30 * 86400]);
    return [$total, $b, $done];
}

/** Expired commands + mobile tokens. Returns [commands, tokens]. */
function job_expire(): array
{
    $a = q("UPDATE commands SET status='expired' WHERE status IN ('queued','sent')
            AND created_at<?", [now_ts() - 86400])->rowCount();
    $b = q('DELETE FROM mtokens WHERE expires_at<?', [now_ts()])->rowCount();
    return [$a, $b];
}

/** Inactivity alarms (create/clear). Returns new alarm count.
 *
 * Rule (decided): if ALL sensors of a client go silent, that is ONE
 * client-level entry (sensor_id NULL) — not a per-sensor pile.
 * Per-sensor inactivity is created only if client is otherwise
 * alive but single sensors lag (e.g. deleted/renamed sensor).
 */
function job_alarms(): int
{
    $now = now_ts();
    $n = 0;
    $clients = qall("SELECT DISTINCT c.* FROM clients c JOIN sensors s ON s.client_id=c.id
                     WHERE s.collect='yes'");
    foreach ($clients as $c) {
        $cid = (int)$c['id'];
        $ss = qall("SELECT id, last_seen, expected_every_s FROM sensors
                    WHERE client_id=? AND collect='yes'", [$cid]);
        if (!$ss) {
            continue;
        }
        $fresh = 0;
        $expMax = 0;
        foreach ($ss as $s) {
            $exp = $s['expected_every_s'] !== null ? (int)$s['expected_every_s']
                : max((int)$c['poll_day_s'], (int)$c['poll_night_s']);
            if ($exp > $expMax) {
                $expMax = $exp;
            }
            if ($s['last_seen'] !== null && (int)$s['last_seen'] > $fresh) {
                $fresh = (int)$s['last_seen'];
            }
        }
        $mult = max(1, (int)$c['inactivity_mult']);
        $lim = $expMax * $mult;
        if ($expMax <= 0 || $fresh === 0) {
            continue; // never reported / no expectation: no alarm
        }
        $silent = $now - $fresh;
        $off = $silent > $lim;
        $ex = q1("SELECT id FROM alarms WHERE client_id=? AND sensor_id IS NULL
                  AND type='inactivity' AND state='active' LIMIT 1", [$cid]);
        if ($off && !$ex) {
            q("INSERT INTO alarms (client_id, sensor_id, type, state, value_, threshold, created_at)
               VALUES (?,?, 'inactivity','active',?,?,?)", [$cid, null, $silent, $lim, $now]);
            $n++;
        } elseif (!$off && $ex) {
            q("UPDATE alarms SET state='cleared', cleared_at=? WHERE id=?", [$now, $ex['id']]);
        }
        if ($off) {
            // Client-level event: this single entry suffices.
            continue;
        }
        // Client alive but single sensors lag → per-sensor alarm.
        foreach ($ss as $s) {
            $exp = $s['expected_every_s'] !== null ? (int)$s['expected_every_s']
                : max((int)$c['poll_day_s'], (int)$c['poll_night_s']);
            if ($exp <= 0 || $s['last_seen'] === null) {
                continue;
            }
            $offS = ($now - (int)$s['last_seen']) > $exp * $mult;
            $exS = q1("SELECT id FROM alarms WHERE sensor_id=? AND type='inactivity'
                       AND state='active' LIMIT 1", [$s['id']]);
            if ($offS && !$exS) {
                q("INSERT INTO alarms (client_id, sensor_id, type, state, value_, threshold, created_at)
                   VALUES (?,?, 'inactivity','active',?,?,?)",
                    [$cid, (int)$s['id'], $now - (int)$s['last_seen'], $exp * $mult, $now]);
                $n++;
            } elseif (!$offS && $exS) {
                q("UPDATE alarms SET state='cleared', cleared_at=? WHERE id=?", [$now, $exS['id']]);
            }
        }
    }
    // User stale rules (also for silent clients — never found
    // without reports). Other kinds evaluated by api_report.
    $n += rules_evaluate_stale();
    return $n;
}

/** Notify send (mail / ntfy). Returns sent count. */
function notify_send(array $c, array $a): bool
{
    $mode = $c['notify_mode'] ?? 'none';
    $to = $c['notify_target'] ?? '';
    if (($mode !== 'mail' && $mode !== 'ntfy') || $to === '') {
        return false;
    }
    $subj = t('mail.notify_subj', $c['name']);
    $msg = t('mail.notify_body', t('alarm.type_' . $a['type']), ($a['value_'] ?? '?'), ($a['threshold'] ?? '?'), fmt_dt((int)$a['created_at']));
    if ($mode === 'mail') {
        return (bool)@mail($to, $subj, $msg, 'Content-Type: text/plain; charset=utf-8');
    }
    $ctx = stream_context_create(['http' => [
        'method' => 'POST', 'header' => "Content-Type: text/plain\r\nTitle: " . $subj,
        'content' => $msg, 'timeout' => 10, 'ignore_errors' => true,
    ]]);
    $r = @file_get_contents($to, false, $ctx);
    return $r !== false;
}

function job_notify(): int
{
    // Auto inactivity alarms send NO mail by default (list only);
    // only manually added rules may mail (later rule engine).
    // So type='inactivity' is skipped.
    $rows = qall("SELECT a.*, c.name cname, c.notify_mode, c.notify_target FROM alarms a
                  JOIN clients c ON c.id=a.client_id
                  WHERE a.state='active' AND a.notified_at IS NULL
                  AND a.type<>'inactivity' LIMIT 50");
    $n = 0;
    foreach ($rows as $a) {
        if (notify_send(
            ['name' => $a['cname'], 'notify_mode' => $a['notify_mode'],
             'notify_target' => $a['notify_target']], $a)) {
            q('UPDATE alarms SET notified_at=? WHERE id=?', [now_ts(), $a['id']]);
            $n++;
        }
    }
    return $n;
}
