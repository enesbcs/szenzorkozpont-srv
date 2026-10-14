<?php
// SensorKozpont — plain user pages + control action + CSV export.

declare(strict_types=1);

function pg_dashboard(array $u): void
{
    $clients = user_clients($u);
    if (!$clients) {
        layout(t('nav.mine'), $u, '<p>' . t('dash.empty') . '</p>');
    }
    $now = now_ts();
    $b = '<table><tr><th>' . t('col.client') . '</th><th>' . t('col.status') . '</th><th>' . t('col.sensors') . '</th></tr>';
    foreach ($clients as $c) {
        $ss = user_sensors($u, (int)$c['id']);
        // Client-level status shows LATEST report (not the
        // oldest sensor): if client alive and sending, time is fresh —
        // singly-stale sensor shows in its own row, and
        // inactivity alarm signals it anyway.
        $latest = null;
        foreach ($ss as $s) {
            if ($s['last_seen'] !== null && ($latest === null || $s['last_seen'] > $latest)) {
                $latest = (int)$s['last_seen'];
            }
        }
        $exp = null;
        foreach ($ss as $s) {
            if ($s['expected_every_s'] !== null
                && ($exp === null || (int)$s['expected_every_s'] > $exp)) {
                $exp = (int)$s['expected_every_s'];
            }
        }
        $st = conn_status($latest, $exp, $now);
        $b .= '<tr><td><a href="' . e(url('/client-sensors?client=' . (int)$c['id'])) . '">' . e($c['name']) . '</a></td>'
            . '<td><span class="dot ' . ($st['online'] ? 'on' : 'off') . '"></span> ' . e($st['ago']) . '</td>'
            . '<td>' . count($ss) . '</td></tr>';
    }
    layout(t('nav.mine'), $u, $b . '</table>');
}

function pg_client_user(array $u, int $cid): void
{
    if (!user_can_client($u, $cid)) {
        http_response_code(403);
        exit(t('err.forbidden'));
    }
    $c = q1('SELECT * FROM clients WHERE id=?', [$cid]);
    if (!$c) {
        redirect(url('/'));
    }
    $now = now_ts();
    $html = user_client_sensors_html($u, $c, $cid, user_sensors($u, $cid), $now);
    $b = '<p><label><input type="checkbox" id="autorefresh" data-secs="60" checked> '
        . t('auto.refresh', 60) . '</label> '
        . '<input id="sensor-search" placeholder="' . t('search.ph') . '" size="20"></p>'
        . user_sensors_css()
        . ($html !== '' ? $html : '<p>' . t('sens.empty') . '</p>');
    layout(t('nav.mine') . ': ' . $c['name'], $u, $b);
}

/** Client sensors: picker table first, then only selected
 * client sensors (back button above list). No mega-page
 * with hundreds of cards even with many clients. */
function pg_all_sensors(array $u): void
{
    $sel = isset($_GET['client']) ? (int)$_GET['client'] : 0;
    if ($sel > 0) {
        pg_one_client_sensors($u, $sel);
        return;
    }
    $now = now_ts();
    // Same status calc as dashboard client table:
    // latest report + largest expected period.
    $b = '<table><tr><th>' . t('col.client') . '</th><th>' . t('col.status') . '</th><th>' . t('col.sensors') . '</th></tr>';
    foreach (user_clients($u) as $c) {
        $cid = (int)$c['id'];
        $ss = user_sensors($u, $cid);
        $latest = null;
        $exp = null;
        foreach ($ss as $s) {
            if ($s['last_seen'] !== null && ($latest === null || $s['last_seen'] > $latest)) {
                $latest = (int)$s['last_seen'];
            }
            if ($s['expected_every_s'] !== null
                && ($exp === null || (int)$s['expected_every_s'] > $exp)) {
                $exp = (int)$s['expected_every_s'];
            }
        }
        $st = conn_status($latest, $exp, $now);
        $b .= '<tr><td><a href="' . e(url('/client-sensors?client=' . $cid)) . '">' . e($c['name']) . '</a></td>'
            . '<td><span class="dot ' . ($st['online'] ? 'on' : 'off') . '"></span> ' . e($st['ago']) . '</td>'
            . '<td>' . count($ss) . '</td></tr>';
    }
    layout('Kliensszenzorok', $u, $b . '</table>');
}

/** Selected client sensors + back button to picker. */
function pg_one_client_sensors(array $u, int $cid): void
{
    $c = q1('SELECT * FROM clients WHERE id=?', [$cid]);
    if (!$c) {
        redirect(url('/client-sensors'));
    }
    if (!user_can_client($u, $cid)) {
        http_response_code(403);
        exit(t('err.forbidden'));
    }
    $now = now_ts();
    $html = user_client_sensors_html($u, $c, $cid, user_sensors($u, $cid), $now);
    // Back to client list = Dashboard (/dashboard for admin, / for user).
    $backTo = is_admin($u) ? '/dashboard' : '/';
    $b = '<form method="get" action="' . e(url($backTo)) . '">'
        . '<button class="ghost" type="submit">' . t('back.clients') . '</button></form>'
        . '<p><label><input type="checkbox" id="autorefresh" data-secs="60" checked> '
        . t('auto.refresh', 60) . '</label> '
        . '<input id="sensor-search" placeholder="' . t('search.ph') . '" size="20"></p>'
        . user_sensors_css()
        . ($html !== '' ? $html : '<p>' . t('sens.empty') . '</p>');
    layout('Kliensszenzorok: ' . $c['name'], $u, $b);
}

/** Shared sensor-grid styles (user client page + admin overview). */
function user_sensors_css(): string
{
    return '<style>'
        . '.sgrid{display:grid;grid-template-columns:1fr 1fr;gap:.7em;margin:.6em 0}'
        . '.sen{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:.7em;min-width:0}'
        . '.sen-stale{background:#fff8e1;border-color:var(--amber)}'
        . '.sen-g{font-weight:700;margin-bottom:.3em}'
        . '.sen-v{font-size:1.15em;margin:.25em 0}'
        . '.sen-s{color:var(--muted);font-size:.9em}'
        . '.sen-s a.stlink{color:inherit;text-decoration:none}'
        . '.sen-s a.stlink:hover{text-decoration:underline}'
        . '.sen-c{margin-top:.4em}'
        . '.sen-c input[type=range]{width:100%}'
        . '.sen-c input[type=color]{width:3em;height:2em;padding:0;border:1px solid #c3ccc7}'
        . '.queued{background:#fff4c2;border:1px solid var(--amber);border-radius:8px;padding:.4em .6em;margin-top:.4em}'
        . '@media (max-width:700px){.sgrid{grid-template-columns:1fr}}'
        . '</style>';
}

/** One sensor card cell (value + status + control). */
function user_sensor_cell(array $s, string $glabel, bool $showGroup, array $c, int $cid, int $now): string
{
    $isCtl = $s['kind'] === 'control' && (int)$s['can_control'] === 1;
    $st = conn_status($s['last_seen'] !== null ? (int)$s['last_seen'] : null,
        $s['expected_every_s'] !== null ? (int)$s['expected_every_s'] : null, $now);
    $exact = $s['last_seen'] !== null ? fmt_dt((int)$s['last_seen']) : '';
    // No data / stale / lagging: pale-yellow highlight (dark theme too).
    $h = '<div class="sen' . ($st['online'] ? '' : ' sen-stale') . '">';
    if ($showGroup) {
        $h .= '<div class="sen-g">' . e($glabel) . '</div>';
    }
    $h .= '<div class="sen-h"><b>' . e($s['label'] ?: $s['sensor_key']) . '</b><br><small>'
        . e($s['sensor_key']) . '</small></div>'
        . '<div class="sen-v"><b>' . e(display_value($s)) . '</b></div>'
        . '<div class="sen-s"' . ($exact !== '' ? ' title="' . e($exact) . '"' : '') . '>'
        . '<a class="stlink" href="' . e(url('/sensors/' . (int)$s['id'] . '/history')) . '">'
        . '<span class="dot ' . ($st['online'] ? 'on' : 'off') . '"></span> ' . e($st['ago'])
        . '</a><br>' . sparkline((int)$s['id']) . '</div>';
    if ($isCtl) {
        $h .= '<div class="sen-c">' . control_form((int)$s['id'], '/clients/' . $cid)
            . queued_control_info($s, $c) . '</div>';
    }
    return $h . '</div>';
}

/** One client's sensors, user split: controllable on top.
 * Group cards + paired single-sensor groups below.
 * Only `collect=yes` rows (rest on admin device list).
 * Empty list returns '' (caller prints placeholder). */
function user_client_sensors_html(array $u, array $c, int $cid, array $ss, int $now): string
{
    $glabelOf = static function (array $s): string {
        if (!empty($s['group_key'])) {
            return $s['group_label'] ?: $s['group_key'];
        }
        return '';
    };
    // STRICT global order: ALL controllable on top (separate
    // block), rest only after — regardless of group.
    $ctls = [];
    $rest = [];
    foreach ($ss as $s) {
        if (($s['collect'] ?? 'yes') !== 'yes') {
            continue;
        }
        if ($s['kind'] === 'control' && (int)$s['can_control'] === 1) {
            $ctls[] = $s;
        } else {
            $rest[] = $s;
        }
    }
    // Group the rest: multi-sensor group gets own card,
    // single-sensor ones share a grid.
    $groups = [];
    foreach ($rest as $s) {
        $gk = !empty($s['group_key']) ? $s['group_key'] : "\0" . $s['sensor_key'];
        if (!isset($groups[$gk])) {
            $groups[$gk] = ['label' => !empty($s['group_key'])
                ? ($s['group_label'] ?: $s['group_key']) : ($s['label'] ?: $s['sensor_key']),
                'items' => []];
        }
        $groups[$gk]['items'][] = $s;
    }
    $b = '';
    $singles = [];
    if ($ctls) {
        $b .= '<div class="card"><h3>' . t('ctl.group', count($ctls)) . '</h3><div class="sgrid">';
        foreach ($ctls as $s) {
            $gl = $glabelOf($s);
            $b .= user_sensor_cell($s, $gl, $gl !== '', $c, $cid, $now);
        }
        $b .= '</div></div>';
    }
    foreach ($groups as $gk => $g) {
        $items = $g['items'];
        if (count($items) === 1) {
            $singles[] = [$g['label'], $items[0]];
            continue;
        }
        $b .= '<div class="card"><h3>' . e($g['label']) . ' (' . count($items) . ')</h3>'
            . '<div class="sgrid">';
        foreach ($items as $s) {
            $b .= user_sensor_cell($s, $g['label'], false, $c, $cid, $now);
        }
        $b .= '</div></div>';
    }
    if ($singles) {
        // Single-sensor groups side by side, two per row.
        $b .= '<div class="sgrid">';
        foreach ($singles as [$label, $s]) {
            $b .= user_sensor_cell($s, $label, true, $c, $cid, $now);
        }
        $b .= '</div>';
    }
    return $b;
}

/** Raw report row display value (calibrated, like display_value). */
function history_value(array $s, array $r): string
{
    if ($r['v_num'] !== null) {
        $v = calibrated((float)$r['v_num'], (float)$s['slope'], (float)$s['offset_']);
        $d = max(0, min(6, (int)$s['decimals']));
        $out = number_format($v, $d, ',', ' ');
        return $out . ($s['unit'] !== '' ? ' ' . $s['unit'] : '');
    }
    if ($r['v_bool'] !== null) {
        return ((int)$r['v_bool'] === 1 ? t('ctl.on') : t('ctl.off'));
    }
    return (string)$r['v_str'];
}

/** CSV cell value: like history_value, with locale decimal sep, unit. */
function csv_value(array $s, array $r): string
{
    if ($r['v_num'] !== null) {
        $v = calibrated((float)$r['v_num'], (float)$s['slope'], (float)$s['offset_']);
        $d = max(0, min(6, (int)$s['decimals']));
        $out = csv_num($v, $d);
        return $out . ($s['unit'] !== '' ? ' ' . $s['unit'] : '');
    }
    if ($r['v_bool'] !== null) {
        return ((int)$r['v_bool'] === 1 ? t('ctl.on') : t('ctl.off'));
    }
    return (string)$r['v_str'];
}

/** Raw DB value to CSV: locale decimal sep if numeric, else unchanged. */
function csv_raw(string $v): string
{
    if (!is_numeric($v)) {
        return $v;
    }
    if (lang_current() === 'hu') {
        return str_replace('.', ',', $v);
    }
    return $v;
}

/** Sensor history subpage (user + admin): chart + list, 24h/7d/14d. */
function pg_sensor_history(array $u, int $sid): void
{
    $s = q1('SELECT * FROM sensors WHERE id=?', [$sid]);
    if (!$s) {
        http_response_code(404);
        exit('Nincs ilyen szenzor.');
    }
    if (!is_admin($u)) {
        $r = resolve_right($u, (int)$s['client_id'], $s);
        if (!$r['view']) {
            http_response_code(403);
            exit(t('err.forbidden'));
        }
    }
    $range = $_GET['range'] ?? '24h';
    if (!in_array($range, ['24h', '7d', '14d'], true)) {
        $range = '24h';
    }
    $secs = ['24h' => 86400, '7d' => 7 * 86400, '14d' => 14 * 86400][$range];
    $now = now_ts();
    $from = $now - $secs;
    $isNum = in_array($s['dtype'], ['float', 'int'], true);

    $title = trim((string)$s['label']) !== '' ? (string)$s['label'] : (string)$s['sensor_key'];
    $tab = static function (string $rg, string $label) use ($sid, $range): string {
        return '<a href="' . e(url('/sensors/' . $sid . '/history?range=' . $rg)) . '" class="'
            . ($range === $rg ? 'act' : '') . '">' . $label . '</a>';
    };
    $st = conn_status($s['last_seen'] !== null ? (int)$s['last_seen'] : null,
        $s['expected_every_s'] !== null ? (int)$s['expected_every_s'] : null, $now);
    $b = '<div class="tabs">' . $tab('24h', t('hist.day')) . $tab('7d', t('hist.week')) . $tab('14d', t('hist.biweek')) . '</div>'
        . '<p><a href="' . e(url('/clients/' . (int)$s['client_id'])) . '">' . t('back.client') . '</a>'
        . ' · <a href="' . e(url('/sensors/' . $sid . '/export?range=' . $range)) . '">CSV-export</a></p>'
        . '<p><span class="dot ' . ($st['online'] ? 'on' : 'off') . '"></span> '
        . t('hist.now') . ' <b>' . e(display_value($s)) . '</b> (' . e($st['ago']) . ')</p>';

    if ($isNum) {
        // Chart: 24h from raw, 7/14d from hourly avg (if any),
        // else thinned raw. Max ~400 points, last always kept.
        $pts = [];
        if ($range !== '24h') {
            $rows = qall('SELECT hour_ts t, havg v FROM readings_hourly
                          WHERE sensor_id=? AND hour_ts>=? AND havg IS NOT NULL
                          ORDER BY hour_ts ASC', [$sid, $from - ($from % 3600)]);
            foreach ($rows as $r2) {
                $pts[] = [(int)$r2['t'], (float)$r2['v']];
            }
        }
        if (count($pts) < 2) {
            $pts = [];
            $rows = qall('SELECT ts_client t, v_num v FROM readings_raw
                          WHERE sensor_id=? AND ts_server>=? AND v_num IS NOT NULL
                          ORDER BY ts_server ASC LIMIT 5000', [$sid, $from]);
            foreach ($rows as $r2) {
                $pts[] = [(int)$r2['t'], (float)$r2['v']];
            }
        }
        $n = count($pts);
        if ($n > 400) {
            $step = (int)ceil($n / 400);
            $dec = [];
            for ($i = 0; $i < $n; $i += $step) {
                $dec[] = $pts[$i];
            }
            if ($dec[count($dec) - 1] !== $pts[$n - 1]) {
                $dec[] = $pts[$n - 1];
            }
            $pts = $dec;
        }
        if (count($pts) >= 2) {
            // Stored value on raw scale → calibrate for display.
            $cal = [];
            foreach ($pts as [$t, $v]) {
                $cal[] = [$t, calibrated($v, (float)$s['slope'], (float)$s['offset_'])];
            }
            $b .= '<h3>' . t('hist.chart') . '</h3>' . history_chart(
                $cal, (string)$s['unit'], max(0, min(6, (int)$s['decimals'])));
        } else {
            $b .= '<p>' . t('hist.nochart') . '</p>';
        }
    }
    // List: latest max 200 entries (datetime + value).
    $rows = qall('SELECT ts_client t, v_num, v_str, v_bool FROM readings_raw
                  WHERE sensor_id=? AND ts_server>=? ORDER BY ts_server DESC LIMIT 200',
        [$sid, $from]);
    $b .= '<h3>' . t('hist.entries') . ($rows ? ' ' . t('hist.recent', count($rows)) : '') . '</h3>';
    if ($rows) {
        $b .= '<div class="wrap"><table><tr><th>' . t('hist.date') . '</th><th>' . t('hist.value') . '</th></tr>';
        foreach ($rows as $r2) {
            $dt = (int)$r2['t'] > 0 ? fmt_dt((int)$r2['t']) : '—';
            $b .= '<tr><td>' . e($dt) . '</td><td><b>' . e(history_value($s, $r2)) . '</b></td></tr>';
        }
        $b .= '</table></div>';
    } else {
        $b .= '<p>' . t('hist.empty') . '</p>';
    }
    layout(t('hist.title', $title), $u, $b);
}

/** CSV export of one sensor history (current period). */
function act_export_sensor(array $u, int $sid): void
{
    $s = q1('SELECT * FROM sensors WHERE id=?', [$sid]);
    if (!$s) {
        http_response_code(404);
        exit('Nincs ilyen szenzor.');
    }
    if (!is_admin($u)) {
        $r = resolve_right($u, (int)$s['client_id'], $s);
        if (!$r['view']) {
            http_response_code(403);
            exit(t('err.forbidden'));
        }
    }
    $range = $_GET['range'] ?? '24h';
    if (!in_array($range, ['24h', '7d', '14d'], true)) {
        $range = '24h';
    }
    $from = now_ts() - ['24h' => 86400, '7d' => 7 * 86400, '14d' => 14 * 86400][$range];
    $rows = qall('SELECT ts_client t, v_num, v_str, v_bool FROM readings_raw
                  WHERE sensor_id=? AND ts_server>=? ORDER BY ts_server ASC LIMIT 20000',
        [$sid, $from]);
    audit($u['name'], 'export_sensor', $s['sensor_key'] . ' (' . count($rows) . ' sor)');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="szenzorkozpont-sensor-' . $sid . '.csv"');
    echo "timestamp,value\n";
    $out = fopen('php://output', 'w');
    foreach ($rows as $r2) {
        $dt = (int)$r2['t'] > 0 ? fmt_dt((int)$r2['t']) : '';
        fputcsv($out, [$dt, csv_value($s, $r2)]);
    }
    fclose($out);
    exit;
}

/** Issue control (web session, CSRF). Returns JSON or redirect. */
function act_control(array $u): void
{
    $sid = (int)($_POST['sensor_id'] ?? 0);
    $state = $_POST['state'] ?? '';
    $back = $_POST['back'] ?? '/';
    $s = q1("SELECT * FROM sensors WHERE id=? AND kind='control' AND collect='yes'", [$sid]);
    $fail = static function (string $msg) use ($back): void {
        if (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false) {
            json_out(['ok' => false, 'err' => $msg], 403);
        }
        flash($msg);
        redirect(url($back));
    };
    if (!$s) {
        $fail(t('ctl.err'));
    }
    [$params, $perr] = control_params_from_post();
    if ($perr !== null) {
        $fail($perr);
    }
    $arg = validate_control_args((string)($_POST['state'] ?? ''), $params, $s['ctl_json'] ?? null);
    if ($arg === null) {
        $fail(t('ctl.err'));
    }
    $r = resolve_right($u, (int)$s['client_id'], $s);
    if (!$r['control']) {
        audit($u['name'], 'control_denied', $s['sensor_key']);
        $fail(t('ctl.denied'));
    }
    $id = enqueue_command((int)$s['client_id'], 'control',
        array_merge(['n' => $s['sensor_key']], $arg), $s['sensor_key']);
    audit($u['name'], 'control', $s['sensor_key'] . ' → ' . control_arg_summary($arg) . " (#$id)");
    if (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false) {
        json_out(['ok' => true, 'command_id' => $id, 'status' => 'pending']);
    }
    flash(t('ctl.sent'));
    redirect(url($back));
}

/** CSV export (admin: any client; user: own only). */
function act_export(array $u): void
{
    $cid = (int)($_GET['client'] ?? 0);
    if (!user_can_client($u, $cid) && !is_admin($u)) {
        http_response_code(403);
        exit(t('err.forbidden'));
    }
    $c = q1('SELECT * FROM clients WHERE id=?', [$cid]);
    if (!$c) {
        http_response_code(404);
        exit(t('err.noclient'));
    }
    $ss = is_admin($u)
        ? qall('SELECT * FROM sensors WHERE client_id=?', [$cid])
        : user_sensors($u, $cid);
    $sids = array_column($ss, 'id');
    if (!$sids) {
        exit(t('err.nodata'));
    }
    $in = implode(',', array_fill(0, count($sids), '?'));
    $rows = qall("SELECT r.ts_client, s.sensor_key,
                  COALESCE(CAST(r.v_num AS TEXT), r.v_str,
                           CASE WHEN r.v_bool IS NULL THEN '' WHEN r.v_bool=1 THEN 'true' ELSE 'false' END) v
                  FROM readings_raw r JOIN sensors s ON s.id=r.sensor_id
                  WHERE r.sensor_id IN ($in) AND r.ts_server>? ORDER BY r.ts_server DESC LIMIT 20000",
        array_merge($sids, [now_ts() - 30 * 86400]));
    audit($u['name'], 'export', $c['name'] . ' (' . count($rows) . ' sor)');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="szenzorkozpont-' . $cid . '.csv"');
    echo "timestamp,sensor,value\n";
    $out = fopen('php://output', 'w');
    foreach ($rows as $r) {
        fputcsv($out, [fmt_dt((int)$r['ts_client']), $r['sensor_key'], csv_raw((string)$r['v'])]);
    }
    fclose($out);
    exit;
}
