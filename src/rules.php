<?php
// SzenzorKozpont — user alarm rules (admin).
//
// Kinds: max/min (threshold on calibrated value), battery (charge, same
// mechanics as min, default 15), stale (lag, in hours),
// binary (ON/OFF edge — edge watch, not level).
// Eval: after report (api_report) on touched sensors; stale
// runs in tick/cron alarm job (also for silent clients — lag
// would never surface without reports).
// Repeat: never an active duplicate (as with built-in threshold);
// once = once total (per sensor), hourly/daily = cooldown since
// last alert. Clear: active row closes when condition no longer
// holds (except once "forever memory" — kept).
// Email: digest only, in cron (job_mail) — report path never mails.

declare(strict_types=1);

const RULE_KINDS = ['max', 'min', 'battery', 'stale', 'binary'];
const RULE_REPEATS = ['once', 'hourly', 'daily'];
function rule_kind_label(string $kind): string
{
    $m = ['max' => t('alarm.type_max'), 'min' => t('alarm.type_min'),
        'battery' => t('alarm.type_battery'), 'stale' => t('alarm.type_stale'),
        'binary' => t('alarm.type_binary')];
    return $m[$kind] ?? $kind;
}

/** Enabled rules for given sensors (with sensor list). */
function rules_for_sensors(array $sids): array
{
    $sids = array_values(array_unique(array_map('intval', $sids)));
    if (!$sids) {
        return [];
    }
    $in = implode(',', array_fill(0, count($sids), '?'));
    $rows = qall("SELECT r.* FROM alarm_rules r JOIN alarm_rule_sensors rs ON rs.rule_id=r.id
                  WHERE r.enabled<>0 AND rs.sensor_id IN ($in)", $sids);
    $out = [];
    foreach ($rows as $r) {
        $rid = (int)$r['id'];
        if (!isset($out[$rid])) {
            $out[$rid] = ['rule' => $r, 'sids' => []];
        }
    }
    if (!$out) {
        return [];
    }
    $rin = implode(',', array_fill(0, count($out), '?'));
    foreach (qall("SELECT rule_id, sensor_id FROM alarm_rule_sensors WHERE rule_id IN ($rin)",
        array_keys($out)) as $m) {
        $out[(int)$m['rule_id']]['sids'][] = (int)$m['sensor_id'];
    }
    return array_values($out);
}

/** Alert + cooldown/once guard. Returns whether a new row was made. */
function rule_fire(array $rule, array $sensor, $value, $threshold): bool
{
    $rid = (int)$rule['id'];
    $sid = (int)$sensor['id'];
    if (q1("SELECT id FROM alarms WHERE rule_id=? AND sensor_id=? AND state='active' LIMIT 1",
        [$rid, $sid])) {
        return false;
    }
    if (($rule['rep'] ?? 'daily') === 'once') {
        if (q1('SELECT id FROM alarms WHERE rule_id=? AND sensor_id=? LIMIT 1', [$rid, $sid])) {
            return false;
        }
    } else {
        $cd = ($rule['rep'] ?? 'daily') === 'hourly' ? 3600 : 86400;
        $last = q1('SELECT MAX(created_at) m FROM alarms WHERE rule_id=? AND sensor_id=?',
            [$rid, $sid]);
        if ($last && $last['m'] !== null && now_ts() - (int)$last['m'] < $cd) {
            return false;
        }
    }
    q('INSERT INTO alarms (client_id, sensor_id, rule_id, type, state, value_, threshold, created_at)
       VALUES (?,?,?,?,?,?,?,?)',
        [(int)$sensor['client_id'], $sid, $rid, $rule['kind'], 'active', $value, $threshold, now_ts()]);
    return true;
}

function rule_clear(int $rid, int $sid): void
{
    q("UPDATE alarms SET state='cleared', cleared_at=? WHERE rule_id=? AND sensor_id=? AND state='active'",
        [now_ts(), $rid, $sid]);
}

/** Post-report eval. $upd: sid => ['v'=>?float,'vb'=>?int,'old_bool'=>?int]. */
function rules_evaluate_report(int $cid, array $upd): void
{
    if (!$upd) {
        return;
    }
    $in = implode(',', array_fill(0, count($upd), '?'));
    $fresh = [];
    foreach (qall("SELECT * FROM sensors WHERE id IN ($in)", array_keys($upd)) as $s) {
        $fresh[(int)$s['id']] = $s;
    }
    $n = 0;
    foreach (rules_for_sensors(array_keys($upd)) as $rr) {
        $rule = $rr['rule'];
        $kind = $rule['kind'];
        if (!in_array($kind, ['max', 'min', 'battery', 'binary'], true)) {
            continue;
        }
        foreach ($rr['sids'] as $sid) {
            if (!isset($upd[$sid], $fresh[$sid])) {
                continue;
            }
            if (++$n > 500) {
                return; // guard: report response must not stretch
            }
            $s = $fresh[$sid];
            if ($kind === 'binary') {
                $tgt = (($rule['target_state'] ?? 'on') === 'on') ? 1 : 0;
                $new = $upd[$sid]['vb'];
                $old = $upd[$sid]['old_bool'];
                if ($new === null) {
                    continue;
                }
                if ($old !== null && $old !== $tgt && $new === $tgt) {
                    rule_fire($rule, $s, $new, $tgt);
                } elseif ($new !== $tgt) {
                    rule_clear((int)$rule['id'], $sid);
                }
                continue;
            }
            if ($s['last_v_num'] === null) {
                continue;
            }
            $thr = (float)$rule['threshold'];
            $cal = calibrated((float)$s['last_v_num'], (float)$s['slope'], (float)$s['offset_']);
            $hit = ($kind === 'min' || $kind === 'battery') ? ($cal < $thr) : ($cal > $thr);
            if ($hit) {
                rule_fire($rule, $s, $cal, $thr);
            } else {
                rule_clear((int)$rule['id'], $sid);
            }
        }
    }
}

/** Stale rules (tick/cron — also for silent clients). Returns new alerts. */
function rules_evaluate_stale(): int
{
    $n = 0;
    $evals = 0;
    foreach (qall("SELECT * FROM alarm_rules WHERE enabled<>0 AND kind='stale'") as $r) {
        $hours = (float)$r['hours'];
        if ($hours <= 0) {
            continue;
        }
        $rid = (int)$r['id'];
        foreach (qall('SELECT s.* FROM sensors s JOIN alarm_rule_sensors rs
                       ON rs.sensor_id=s.id WHERE rs.rule_id=?', [$rid]) as $s) {
            if (++$evals > 2000) {
                return $n;
            }
            $sid = (int)$s['id'];
            $ref = $s['last_seen'] !== null ? (int)$s['last_seen'] : (int)$s['first_seen'];
            $elH = (now_ts() - $ref) / 3600;
            if ($elH > $hours) {
                if (rule_fire($r, $s, round($elH, 1), $hours)) {
                    $n++;
                }
            } else {
                rule_clear($rid, $sid);
            }
        }
    }
    return $n;
}

/** Email digest: one mail per recipient on new alerts. Returns recipients. */
function job_mail(): int
{
    $rows = qall("SELECT a.id, a.created_at, a.value_, a.threshold, a.type,
                         r.id rid, r.name rname, r.notify_target,
                         c.name cname, s.sensor_key skey, s.label slabel
                  FROM alarms a JOIN alarm_rules r ON r.id=a.rule_id
                  JOIN clients c ON c.id=a.client_id
                  LEFT JOIN sensors s ON s.id=a.sensor_id
                  WHERE a.state='active' AND a.notified_at IS NULL
                  AND a.rule_id IS NOT NULL AND r.enabled<>0 AND r.notify_mode='email'
                  ORDER BY r.notify_target, a.created_at LIMIT 200");
    $groups = [];
    foreach ($rows as $r) {
        $to = trim((string)($r['notify_target'] ?? ''));
        if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            continue;
        }
        $groups[$to][] = $r;
    }
    $sent = 0;
    foreach ($groups as $to => $items) {
        $rules = [];
        $lines = [];
        foreach ($items as $it) {
            $rules[(int)$it['rid']] = true;
            $sname = trim((string)($it['slabel'] ?? '')) !== ''
                ? (string)$it['slabel'] : (string)($it['skey'] ?? '?');
            $lines[] = '- ' . fmt_dt((int)$it['created_at']) . ' | ' . $it['cname'] . ' | '
                . $sname . ' | ' . $it['rname'] . ' | ' . t('mail.lbl_value', ($it['value_'] ?? '?'))
                . ', ' . t('mail.lbl_thr', ($it['threshold'] ?? '?'));
        }
        $subj = t('mail.digest_subj', count($items), count($rules));
        if (smtp_send($to, $subj, t('mail.digest_head') . "\n" . implode("\n", $lines) . "\n")) {
            $ids = array_column($items, 'id');
            $in = implode(',', array_fill(0, count($ids), '?'));
            q("UPDATE alarms SET notified_at=? WHERE id IN ($in)", array_merge([now_ts()], $ids));
            $sent++;
        }
    }
    return $sent;
}
