<?php
// SensorKozpont — admin pages and actions.

declare(strict_types=1);

function pg_login(?string $msg, int $wait): void
{
    $w = $wait > 0 ? '<p class="warn">' . t('login.wait', $wait) . '</p>' : '';
    $m = $msg !== null ? '<p class="warn">' . e($msg) . '</p>' : '';
    layout(t('login.title'), null, $m . $w
        . '<form method="post" action="' . e(url('/login')) . '">'
        . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
        . '<label>' . t('login.user') . '<br><input name="username" autocomplete="username"></label><br>'
        . '<label>' . t('login.pass') . '<br><input type="password" name="password" autocomplete="current-password"></label><br>'
        . '<button type="submit">' . t('login.submit') . '</button></form>');
}

// --------------------------------------------------------------- clients ---

/** Admin dashboard: one row per client (aggregate queries,
 * no per-client N+1, stays fast with many devices). */
function pg_dashboard_admin(array $u): void
{
    $now = now_ts();
    $mx = db_driver() === 'mysql'
        ? 'GREATEST(c.poll_day_s, c.poll_night_s)'
        : 'max(c.poll_day_s, c.poll_night_s)';
    $ss = [];
    foreach (qall("SELECT s.client_id cid,
                   COUNT(CASE WHEN s.collect='yes' THEN 1 END) nsens,
                   MAX(CASE WHEN s.collect='yes' THEN s.last_seen END) last_seen,
                   SUM(CASE WHEN s.collect='yes' AND (s.last_seen IS NULL
                       OR s.last_seen < ? - COALESCE(s.expected_every_s, $mx) * 3)
                       THEN 1 ELSE 0 END) nstale,
                   SUM(CASE WHEN s.collect='pending' THEN 1 ELSE 0 END) npend
                   FROM sensors s JOIN clients c ON c.id=s.client_id
                   GROUP BY s.client_id", [$now]) as $r) {
        $ss[(int)$r['cid']] = $r;
    }
    $aa = [];
    foreach (qall("SELECT client_id cid, COUNT(*) n FROM alarms
                   WHERE state='active' GROUP BY client_id") as $r) {
        $aa[(int)$r['cid']] = (int)$r['n'];
    }
    $b = '<h2>' . t('dash.newclient') . '</h2><form method="post" action="' . e(url('/clients/add')) . '">'
        . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
        . '<input name="name" placeholder="' . t('user.name_ph') . '" required> <input name="note" placeholder="' . t('cli.note_ph') . '"> '
        . '<button type="submit">' . t('cli.add') . '</button></form>';
    $b .= '<h2>' . t('dash.clients') . '</h2><table><tr><th>' . t('col.client') . '</th><th>' . t('col.type') . '</th><th>' . t('col.status') . '</th><th>' . t('col.polling') . '</th>'
        . '<th>' . t('col.sensors') . '</th><th>' . t('col.alarms') . '</th><th></th></tr>';
    foreach (qall('SELECT c.*, si.extra extra FROM clients c
                   LEFT JOIN sysinfo si ON si.client_id=c.id ORDER BY c.name') as $c) {
        $cid = (int)$c['id'];
        $s = $ss[$cid] ?? ['nsens' => 0, 'last_seen' => null, 'nstale' => 0, 'npend' => 0];
        $exp = max((int)$c['poll_day_s'], (int)$c['poll_night_s']);
        $st = conn_status($s['last_seen'] !== null ? (int)$s['last_seen'] : null, $exp, $now);
        $ctype = null;
        if (!empty($c['extra'])) {
            $ex = json_decode((string)$c['extra'], true);
            if (is_array($ex) && isset($ex['ctype']) && $ex['ctype'] !== '') {
                $ctype = (string)$ex['ctype'];
            }
        }
        $tlabel = client_type_label($ctype);
        $nal = $aa[$cid] ?? 0;
        $b .= '<tr><td><a href="' . e(url('/client-sensors?client=' . $cid)) . '">' . e($c['name']) . '</a></td>'
            . '<td>' . e($tlabel) . '</td>'
            . '<td><span class="dot ' . ($st['online'] ? 'on' : 'off') . '"></span> '
            . e($st['ago']) . ' <small>(' . e($st['label']) . ')</small></td>'
            . '<td>' . (int)$c['poll_day_s'] . 's / ' . (int)$c['poll_night_s'] . 's</td>'
            . '<td>' . (int)$s['nsens']
            . ((int)$s['nstale'] > 0 ? ' <b>(' . t('dash.stale_n', (int)$s['nstale']) . ')</b>' : '')
            . ((int)$s['npend'] > 0 ? ' <b>(' . t('dash.pend_n', (int)$s['npend']) . ')</b>' : '') . '</td>'
            . '<td>' . ($nal > 0 ? '<b>' . $nal . '</b>' : '0') . '</td>'
            . '<td><form method="post" action="' . e(url('/clients/' . $cid . '/rename')) . '" style="display:inline;margin-right:.8em">'
            . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
            . '<input name="name" value="' . e($c['name']) . '" size="12" maxlength="100" required> '
            . '<button type="submit">' . t('dash.rename') . '</button></form> '
            . '<form method="get" action="' . e(url('/clients/' . $cid)) . '" style="display:inline;margin-right:.8em">'
            . '<button type="submit">' . t('dash.manage') . '</button></form> '
            . '<form method="post" action="' . e(url('/clients/' . $cid . '/delete')) . '" '
            . 'style="display:inline" onsubmit="return confirm(\'' . t('dash.confirm') . '\')">'
            . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
            . '<button type="submit" class="danger">' . t('rule.delete') . '</button></form></td></tr>';
    }
    $b .= '</table>';
    layout(t('nav.dashboard'), $u, $b);
}

/** Rename client (name only). */
function act_client_rename(array $u, int $cid): void
{
    $c = q1('SELECT * FROM clients WHERE id=?', [$cid]);
    $name = trim($_POST['name'] ?? '');
    if (!$c) {
        redirect(url('/dashboard'));
    }
    if ($name === '' || strlen($name) > 100) {
        flash(t('dash.rename_err'));
        redirect(url('/dashboard'));
    }
    if ($name !== $c['name']) {
        q('UPDATE clients SET name=? WHERE id=?', [$name, $cid]);
        audit($u['name'], 'client_rename', $c['name'] . ' -> ' . $name . ' #' . $cid);
        flash(t('dash.renamed'));
    }
    redirect(url('/dashboard'));
}

function sensor_row(array $s, int $now, array $trigKeys = []): string
{
    $st = conn_status($s['last_seen'] !== null ? (int)$s['last_seen'] : null,
        $s['expected_every_s'] !== null ? (int)$s['expected_every_s'] : null, $now);
    $id = (int)$s['id'];
    $coll = ['yes' => t('collect.yes'), 'no' => t('collect.no'), 'pending' => t('collect.pending')];
    $o = '<tr><td><input type="checkbox" name="sel[' . $id . ']" value="1"></td>'
        . '<td>' . e($s['sensor_key']) . '<br><small>' . e($s['label']) . '</small></td>';
    $o .= '<td><input name="glabel[' . $id . ']" value="' . e((string)($s['group_label'] ?? '')) . '" size="12"><br>'
        . '<small>' . e((string)($s['group_key'] ?? '—')) . '</small>'
        . '<br><input name="gkey[' . $id . ']" value="' . e((string)($s['group_key'] ?? '')) . '" size="12"></td>';
    $o .= '<td>' . e($s['kind']) . '/' . e($s['dtype']) . ctl_badge($s['ctl_json'] ?? null) . '<br><small>' . e($s['unit']) . '</small></td>';
    $o .= '<td><select name="coll[' . $id . ']">';
    foreach ($coll as $k => $v) {
        $o .= '<option value="' . $k . '"' . ($s['collect'] === $k ? ' selected' : '') . '>' . $v . '</option>';
    }
    $o .= '</select><br><label><input type="checkbox" name="store[' . $id . ']" value="1"'
        . ((int)$s['store_history'] === 1 ? ' checked' : '') . '> hist</label></td>';
    $o .= '<td><input name="dec[' . $id . ']" value="' . (int)$s['decimals'] . '" size="2" title="tizedes">'
        . '<br><input name="amin[' . $id . ']" value="' . e($s['alm_min'] === null ? '' : (string)$s['alm_min']) . '" size="5" placeholder="min">'
        . '<br><input name="amax[' . $id . ']" value="' . e($s['alm_max'] === null ? '' : (string)$s['alm_max']) . '" size="5" placeholder="max"></td>';
    $o .= '<td>' . e(display_value($s)) . '<br><small>'
        . ($s['last_ts_client'] !== null ? fmt_dt((int)$s['last_ts_client']) : '—') . '</small><br>'
        . '<span class="dot ' . ($st['online'] ? 'on' : 'off') . '"></span> ' . sparkline($id) . '</td>';
    // Trigger marks: only on enabled bool sensors (instant report on change).
    // Checkbox mirrors client LAST report — save queues
    // full-replace set_triggers command.
    if ($s['collect'] === 'yes' && $s['dtype'] === 'bool') {
        $on = in_array($s['sensor_key'], $trigKeys, true);
        $o .= '<td><label><input type="checkbox" name="trig[' . $id . ']" value="1"'
            . ($on ? ' checked' : '') . ' title="' . t('trig.watch') . '">' . t('trig.label') . '</label></td>';
    } else {
        $o .= '<td>—</td>';
    }
    return $o . '</tr>';
}

/** Client last reported trigger list (sensor keys). */
function trigger_reported_keys(int $cid): array
{
    $row = q1('SELECT triggers_json FROM triggers_state WHERE client_id=?', [$cid]);
    if (!$row) {
        return [];
    }
    $d = json_decode((string)$row['triggers_json'], true);
    if (!is_array($d)) {
        return [];
    }
    $out = [];
    foreach ($d as $t) {
        if (is_array($t) && isset($t['n']) && is_string($t['n']) && $t['n'] !== '') {
            $out[] = $t['n'];
        }
    }
    return $out;
}

/** Client trigger-report time (null = never sent). */
function trigger_reported_at(int $cid): ?int
{
    $row = q1('SELECT updated_at FROM triggers_state WHERE client_id=?', [$cid]);
    if (!$row || (int)$row['updated_at'] <= 0) {
        return null;
    }
    return (int)$row['updated_at'];
}

/** Build + queue trigger command (always FULL replace).
 * Returns [n, errmsg] — over 10 not queued. */
function enqueue_trigger_set(int $cid, array $sensorIds): array
{
    $ids = [];
    foreach ($sensorIds as $sid) {
        $sid = (int)$sid;
        if ($sid > 0) {
            $ids[] = $sid;
        }
    }
    $items = [];
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $par = array_merge([$cid], $ids);
        foreach (qall("SELECT sensor_key FROM sensors WHERE client_id=? AND collect='yes'
                       AND dtype='bool' AND id IN ($in) ORDER BY sensor_key", $par) as $r) {
            $items[] = ['n' => (string)$r['sensor_key'], 'on' => 'change'];
        }
    }
    if (count($items) > 10) {
        return [0, t('act.trig_limit', count($items))];
    }
    enqueue_command($cid, 'set_triggers', ['full' => true, 'triggers' => $items]);
    return [count($items), ''];
}

/** Sensor filter for client page (GET + POST, for bulk ops). */
function sensor_filter(): array
{
    $r = $_REQUEST;
    $f = ['kind' => '', 'dtype' => '', 'collect' => '', 'q' => ''];
    if (isset($r['f_kind']) && in_array($r['f_kind'], ['telemetry', 'control'], true)) {
        $f['kind'] = $r['f_kind'];
    }
    if (isset($r['f_dtype']) && in_array($r['f_dtype'], ['float', 'int', 'bool', 'string'], true)) {
        $f['dtype'] = $r['f_dtype'];
    }
    if (isset($r['f_collect']) && in_array($r['f_collect'], ['yes', 'no', 'pending'], true)) {
        $f['collect'] = $r['f_collect'];
    }
    if (isset($r['f_q']) && is_string($r['f_q'])) {
        $f['q'] = substr(trim($r['f_q']), 0, 50);
    }
    $sql = '';
    $par = [];
    if ($f['kind'] !== '') {
        $sql .= ' AND kind=?';
        $par[] = $f['kind'];
    }
    if ($f['dtype'] !== '') {
        $sql .= ' AND dtype=?';
        $par[] = $f['dtype'];
    }
    if ($f['collect'] !== '') {
        $sql .= ' AND collect=?';
        $par[] = $f['collect'];
    }
    if ($f['q'] !== '') {
        $sql .= ' AND (sensor_key LIKE ? ESCAPE \'!\' OR label LIKE ? ESCAPE \'!\' OR group_label LIKE ? ESCAPE \'!\')';
        $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $f['q']) . '%';
        $par[] = $like;
        $par[] = $like;
        $par[] = $like;
    }
    return [$f, $sql, $par];
}

function filter_form(int $cid, array $f, string $tab): string
{
    $sel = static function (string $name, array $opts, string $cur, string $all): string {
        $o = '<select name="' . $name . '"><option value="">' . $all . '</option>';
        foreach ($opts as $k => $v) {
            $o .= '<option value="' . $k . '"' . ($cur === (string)$k ? ' selected' : '') . '>' . $v . '</option>';
        }
        return $o . '</select> ';
    };
    return '<form method="get" action="' . e(url('/clients/' . $cid)) . '">'
        . '<input type="hidden" name="tab" value="' . e($tab) . '">'
        . $sel('f_kind', ['telemetry' => t('flt.kind_tele'), 'control' => t('flt.kind_ctl')], $f['kind'], t('flt.all'))
        . $sel('f_dtype', ['float' => t('flt.float'), 'int' => t('flt.int'), 'bool' => t('flt.bool'), 'string' => t('flt.string')], $f['dtype'], t('flt.all'))
        . '<input name="f_q" value="' . e($f['q']) . '" size="14" placeholder="'
        . t('flt.search_ph') . '"> '
        . '<button type="submit">' . t('flt.filter') . '</button> '
        . '<a href="' . e(url('/clients/' . $cid)) . '?tab=' . $tab . '">' . t('flt.clear') . '</a></form>';
}

function pg_client_detail(array $u, int $cid, ?string $newKey = null): void
{
    $c = q1('SELECT * FROM clients WHERE id=?', [$cid]);
    if (!$c) {
        flash(t('act.noclient'));
        redirect(url('/dashboard'));
    }
    $now = now_ts();
    $b = '<form method="get" action="' . e(url('/dashboard')) . '">'
        . '<button class="ghost" type="submit">← Vissza a kliensekhez</button></form>';
    if ($newKey !== null) {
        $b .= '<p class="warn"><b>' . t('keys.fresh') . '</b><br><code>'
            . e($newKey) . '</code></p>';
    }
    // Sensors — three tabs: pending / enabled / denied.
    $cntP = (int)(q1("SELECT COUNT(*) c FROM sensors WHERE client_id=? AND collect='pending'", [$cid])['c'] ?? 0);
    $cntY = (int)(q1("SELECT COUNT(*) c FROM sensors WHERE client_id=? AND collect='yes'", [$cid])['c'] ?? 0);
    $cntN = (int)(q1("SELECT COUNT(*) c FROM sensors WHERE client_id=? AND collect='no'", [$cid])['c'] ?? 0);
    $tab = $_GET['tab'] ?? '';
    if (!in_array($tab, ['pending', 'yes', 'no'], true)) {
        // Default tab always pending (even if empty):
        // new sensors show up here for approval.
        $tab = 'pending';
    }
    $tabUrl = static function (string $t) use ($cid): string {
        return url('/clients/' . $cid) . '?tab=' . $t;
    };
    $tabName = ['pending' => t('cli.tab_pending'), 'yes' => t('cli.tab_yes'), 'no' => t('cli.tab_no')][$tab];
    $b .= '<div class="tabs">'
        . '<a href="' . e($tabUrl('pending')) . '" class="' . ($tab === 'pending' ? 'act' : '') . '">'
        . t('cli.tab_pending') . '<span class="pill warn">' . $cntP . '</span></a>'
        . '<a href="' . e($tabUrl('yes')) . '" class="' . ($tab === 'yes' ? 'act' : '') . '">'
        . t('cli.tab_yes') . '<span class="pill">' . $cntY . '</span></a>'
        . '<a href="' . e($tabUrl('no')) . '" class="' . ($tab === 'no' ? 'act' : '') . '">'
        . t('cli.tab_no') . '<span class="pill bad">' . $cntN . '</span></a></div>';
    $_REQUEST['f_collect'] = $tab;
    [$flt, $fSql, $fPar] = sensor_filter();
    $sensors = qall("SELECT * FROM sensors WHERE client_id=? AND collect=? $fSql ORDER BY
        group_key, sort_no, label", array_merge([$cid, $tab], $fPar));
    $b .= '<h2>' . e($tabName) . ' (' . count($sensors) . ')</h2>' . filter_form($cid, $flt, $tab)
        . '<form method="post" action="' . e(url('/clients/' . $cid . '/sensors')) . '">'
        . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
        . '<input type="hidden" name="tab" value="' . e($tab) . '">'
        . '<input type="hidden" name="f_kind" value="' . e($flt['kind']) . '">'
        . '<input type="hidden" name="f_dtype" value="' . e($flt['dtype']) . '">'
        . '<input type="hidden" name="f_q" value="' . e($flt['q']) . '">'
        . '<div class="wrap"><table><tr><th><input type="checkbox" data-select-all title="' . t('cli.select_all') . '"></th><th>' . t('cli.th_key') . '</th><th>' . t('cli.th_group') . '</th><th>' . t('cli.th_type') . '</th><th>' . t('cli.th_collect') . '</th>'
        . '<th>' . t('cli.th_dec') . '</th><th>' . t('cli.th_last') . '</th><th>' . t('cli.th_trig') . '</th></tr>';
    $trigKeys = trigger_reported_keys($cid);
    foreach ($sensors as $s) {
        $b .= sensor_row($s, $now, $trigKeys);
    }
    $b .= '</table></div>'
        . '<div class="toolbar">'
        . '<button type="submit" name="op" value="save">' . t('cli.bulk_save') . '</button> '
        . '<button type="submit" name="op" value="bulk_approve">' . t('cli.bulk_approve') . '</button> '
        . '<button type="submit" name="op" value="bulk_deny" class="danger">' . t('cli.bulk_deny') . '</button> '
        . '<button type="submit" name="op" value="filter_approve" class="ghost">' . t('cli.bulk_fapprove') . '</button> '
        . '<button type="submit" name="op" value="filter_deny" class="ghost danger">' . t('cli.bulk_fdeny') . '</button></div></form>';
    if ($tab === 'pending' && $cntP > 0) {
        $b .= '<form method="post" action="' . e(url('/clients/' . $cid . '/pending')) . '">'
            . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
            . '<button name="op" value="approve_all">' . t('cli.approve_all', $cntP) . '</button></form>';
    }
    $b .= '<p><a href="' . e(url('/clients/' . $cid . '/triggers')) . '">' . t('cli.trig_matrix') . '</a> '
        . '<small>' . t('cli.trig_note') . '</small></p>';

    // Sysinfo (client actual polling) — also needed by polling section
    $si = q1('SELECT * FROM sysinfo WHERE client_id=?', [$cid]);
    $siPoll = null;
    $siExtra = [];
    if ($si) {
        $ex = json_decode((string)($si['extra'] ?? ''), true);
        if (is_array($ex)) {
            $siExtra = $ex;
        }
        if (is_array($ex['poll'] ?? null)) {
            $siPoll = $ex['poll'];
        }
    }

    // Polling + notifications
    $b .= '<h2>' . t('cli.poll_notify') . '</h2><form method="post" action="' . e(url('/clients/' . $cid . '/poll')) . '">'
        . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
        . t('cli.poll_line',
            '<input name="poll_day_s" value="' . (int)$c['poll_day_s'] . '" size="5">',
            e($c['day_from']), e($c['day_to']),
            '<input name="poll_night_s" value="' . (int)$c['poll_night_s'] . '" size="5">')
        . ' <button type="submit">' . t('btn.save') . '</button></form>'
        . '<small>';
    if ($siPoll !== null) {
        $ovr = ((array)($siPoll['override'] ?? []) !== [])
            ? t('cli.poll_override', e(json_encode($siPoll['override'], JSON_UNESCAPED_UNICODE))) : '';
        $b .= t('cli.poll_actual', e(fmt_dt((int)$si['ts'])),
            (int)($siPoll['day_s'] ?? 0), (int)($siPoll['night_s'] ?? 0),
            (int)($siPoll['interval_s'] ?? 0), e((string)($siPoll['scope'] ?? '')), $ovr);
    } else {
        $b .= t('cli.poll_none');
    }
    $b .= '</small><br>';
    $dayStart = (int)(floor(now_ts() / 86400) * 86400);
    $used = (int)((q1('SELECT COUNT(*) c FROM readings_raw WHERE client_id=? AND ts_server>=?',
        [$cid, $dayStart]))['c'] ?? 0);
    $qlimit = quota_limit($cid);
    $b .= '<small>' . t('cli.quota', $used, $qlimit)
        . t('cli.quota_note')
        . ($used >= $qlimit ? t('cli.quota_hit') : '')
        . '</small><br>';
    $b .= '<form method="post" action="' . e(url('/clients/' . $cid . '/notify')) . '">'
        . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
        . '<select name="notify_mode"><option value="none">' . t('cli.notify_none') . '</option>'
        . '<option value="mail"' . ($c['notify_mode'] === 'mail' ? ' selected' : '') . '>e-mail</option>'
        . '<option value="ntfy"' . ($c['notify_mode'] === 'ntfy' ? ' selected' : '') . '>ntfy</option></select> '
        . '<input name="notify_target" value="' . e($c['notify_target']) . '" size="30" placeholder="' . t('cli.notify_target_ph') . '"> '
        . '<button type="submit">' . t('btn.save') . '</button></form>'
        . '<form method="post" action="' . e(url('/clients/' . $cid . '/debug')) . '">'
        . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
        . '<label><input type="checkbox" name="debug" value="1"'
        . ((int)($c['debug'] ?? 0) === 1 ? ' checked' : '') . '> ' . t('cli.debug') . ' '
        . '<small>(' . t('cli.debug_note') . ')</small></label> '
        . '<button type="submit">' . t('btn.save') . '</button></form>';

    // Queue command — param-less simple commands only.
    // Control (on/off), lamp and thermostat params removed here (form too complex);
    // act_cmd still handles 'control' (API-level, for tests).
    $b .= '<h2>' . t('cli.cmd_h2') . '</h2><form method="post" action="' . e(url('/clients/' . $cid . '/cmd')) . '">'
        . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
        . '<select name="type"><option value="request_config">' . t('cli.cmd_req_config') . '</option>'
        . '<option value="request_sysinfo">' . t('cli.cmd_req_sysinfo') . '</option>'
        . '<option value="request_triggers">' . t('cli.cmd_req_trig') . '</option>'
        . '<option value="set_poll_day">' . t('cli.cmd_set_day') . '</option>'
        . '<option value="set_poll_night">' . t('cli.cmd_set_night') . '</option>'
        . '<option value="ota_notice">' . t('cli.cmd_ota_notice') . '</option>'
        . '<option value="ota_update">' . t('cli.cmd_ota_update') . '</option></select> '
        . t('cli.cmd_value') . ' <input name="value_s" size="6"> '
        . t('cli.cmd_rel') . ' <select name="rel_id"><option value="">—</option>';
    foreach (qall('SELECT * FROM ota_releases ORDER BY client_type, created_at DESC') as $r) {
        $b .= '<option value="' . (int)$r['id'] . '">'
            . e($r['client_type'] . ' ' . $r['version'])
            . ($r['channel'] !== 'stable' ? ' (' . e($r['channel']) . ')' : '') . '</option>';
    }
    $b .= '</select> <button type="submit">' . t('cli.cmd_queue') . '</button></form>';

    // Queued commands
    $cmds = qall("SELECT * FROM commands WHERE client_id=? AND status IN ('queued','sent')
                  ORDER BY id", [$cid]);    if ($cmds) {
        $b .= '<h3>' . t('cli.queued_h3') . '</h3><table><tr><th>' . t('cli.q_id') . '</th><th>' . t('cli.q_type') . '</th><th>' . t('cli.q_target') . '</th><th>' . t('cli.q_status') . '</th></tr>';
        foreach ($cmds as $m) {
            $b .= '<tr><td>' . (int)$m['id'] . '</td><td>' . e($m['type']) . '</td>'
                . '<td>' . e((string)($m['target_n'] ?? '')) . ' ' . e(substr($m['payload'], 0, 80)) . '</td>'
                . '<td>' . e($m['status']) . '</td></tr>';
        }
        $b .= '</table>';
    }
    $b .= '<h2>' . t('cli.raw_h2') . '</h2><p>' . t('cli.raw_p') . '</p><p>'
        . '<a href="' . e(url('/clients/' . $cid . '/raw?type=config')) . '">' . t('cli.raw_cfg') . '</a> · '
        . '<a href="' . e(url('/clients/' . $cid . '/raw?type=report')) . '">' . t('cli.raw_rep') . '</a></p>';

    // Sysinfo + OTA status ($si already loaded above)
    if ($si) {
        $siCtype = (string)($siExtra['ctype'] ?? '');
        $siArch = (string)($siExtra['arch'] ?? '');
        if ($siArch === '' || $siArch === '?' || $siArch === 'unknown') {
            $siArch = '';
        }
        $siHa = (string)($siExtra['ha'] ?? '');
        if ($siHa === '' || $siHa === '?') {
            $siHa = '';
        }
        $b .= '<h2>' . t('cli.sys_h2') . '</h2><table><tr><th>' . t('cli.sys_field') . '</th><th>' . t('cli.sys_value') . '</th></tr>'
            . '<tr><td>' . t('cli.sys_time') . '</td><td>' . e(fmt_dt((int)$si['ts'])) . '</td></tr>'
            . '<tr><td>' . t('cli.sys_type') . '</td><td>' . e(client_type_label($siCtype)) . '</td></tr>'
            . '<tr><td>' . t('cli.sys_fw') . '</td><td>' . e($si['fw_v']) . '</td></tr>'
            . '<tr><td>' . t('cli.sys_variant') . '</td><td>' . e(isset($si['variant']) && $si['variant'] !== '' ? (string)$si['variant'] : '—') . '</td></tr>'
            . '<tr><td>' . t('cli.sys_cpu') . '</td><td>' . (trim((string)$si['cpu']) !== '' ? e((string)$si['cpu']) : '—') . '</td></tr>'
            . '<tr><td>' . t('cli.sys_runtime') . '</td><td>' . (trim((string)$si['runtime']) !== '' ? e((string)$si['runtime']) : '—') . '</td></tr>'
            . ($siArch !== '' ? '<tr><td>' . t('cli.sys_arch') . '</td><td>' . e($siArch) . '</td></tr>' : '')
            . ($siHa !== '' ? '<tr><td>' . t('cli.sys_haver') . '</td><td>' . e($siHa) . '</td></tr>' : '')
            . '<tr><td>' . t('cli.sys_free') . '</td><td>' . e(fmt_mb($si['storage_free'] ?? null)) . '</td></tr>'
            . ($siPoll !== null
                ? '<tr><td>' . t('cli.sys_poll') . '</td><td>' . t('cli.sys_poll_line', (int)($siPoll['day_s'] ?? 0), (int)($siPoll['night_s'] ?? 0), (int)($siPoll['interval_s'] ?? 0), e((string)($siPoll['scope'] ?: '?')), ((array)($siPoll['override'] ?? []) !== [] ? t('cli.poll_override', e(json_encode($siPoll['override'], JSON_UNESCAPED_UNICODE))) : ''))
                  . '<br><small>' . t('cli.sys_took', (int)$c['poll_day_s'], (int)$c['poll_night_s']) . '</small></td></tr>'
                : '')
            . '</table>';
    }
    layout(t('cli.title', $c['name']), $u, $b
        . '<p><a href="' . e(url('/export?client=' . $cid)) . '">' . t('cli.export') . '</a></p>');
}

// ---------------------------------------------------------------- actions ---

function need_admin(?array $u): void
{
    if (!is_admin($u)) {
        http_response_code(403);
        exit('Tiltott.');
    }
}

function act_client_add(array $u): void
{
    $name = trim($_POST['name'] ?? '');
    if ($name === '' || strlen($name) > 100) {
        flash(t('act.badname'));
        redirect(url('/dashboard'));
    }
    q('INSERT INTO clients (name, note, created_at) VALUES (?,?,?)',
        [$name, substr(trim($_POST['note'] ?? ''), 0, 500), now_ts()]);
    $id = last_id();
    audit($u['name'], 'client_add', $name . ' #' . $id);
    flash(t('act.added'));
    redirect(url('/clients/' . $id));
}

function act_client_delete(array $u, int $cid): void
{
    $c = q1('SELECT * FROM clients WHERE id=?', [$cid]);
    if (!$c) {
        redirect(url('/dashboard'));
    }
    foreach (['api_keys', 'sensor_attrs', 'sensor_acl', 'group_acl', 'client_acl',
              'readings_raw', 'readings_hourly', 'commands', 'alarms', 'sysinfo'] as $t) {
        if ($t === 'sensor_attrs') {
            q("DELETE FROM sensor_attrs WHERE sensor_id IN (SELECT id FROM sensors WHERE client_id=?)", [$cid]);
        } elseif ($t === 'sensor_acl') {
            q("DELETE FROM sensor_acl WHERE sensor_id IN (SELECT id FROM sensors WHERE client_id=?)", [$cid]);
        } else {
            q("DELETE FROM $t WHERE client_id=?", [$cid]);
        }
    }
    q('DELETE FROM sensors WHERE client_id=?', [$cid]);
    q('DELETE FROM clients WHERE id=?', [$cid]);
    audit($u['name'], 'client_del', $c['name'] . ' #' . $cid);
    flash(t('act.deleted'));
    redirect(url('/dashboard'));
}

function act_sensors_save(array $u, int $cid): void
{
    $op = $_POST['op'] ?? 'save';
    // Bulk approve/deny: selected or filtered list.
    if (in_array($op, ['bulk_approve', 'bulk_deny', 'filter_approve', 'filter_deny'], true)) {
        $nc = (substr($op, -7) === 'approve') ? 'yes' : 'no';
        if (substr($op, 0, 4) === 'bulk') {
            $ids = [];
            foreach ((array)($_POST['sel'] ?? []) as $sid => $v) {
                if ((int)$v === 1 && (int)$sid > 0) {
                    $ids[] = (int)$sid;
                }
            }
            if ($ids) {
                $in = implode(',', array_fill(0, count($ids), '?'));
                $n = q("UPDATE sensors SET collect=? WHERE client_id=? AND id IN ($in)",
                    array_merge([$nc, $cid], $ids))->rowCount();
                bump_rev($cid);
                audit($u['name'], 'bulk_' . $nc, "client #$cid ($n db)");
                flash(t('act.bulk_n', $n));
            } else {
                flash(t('act.noselect'));
            }
        } else {
            // Tab already narrows: tab provides f_collect if not explicit.
            if (($_POST['f_collect'] ?? '') === ''
                && isset($_POST['tab'])
                && in_array($_POST['tab'], ['pending', 'yes', 'no'], true)) {
                $_REQUEST['f_collect'] = $_POST['tab'];
            }
            [$flt2, $fSql, $fPar] = sensor_filter();
            // Tab narrows (f_collect always set); only with empty
            // filter exclude pending, so they need separate approval.
            $excl = ($flt2['collect'] === '') ? " AND collect<>'pending'" : '';
            $n = q("UPDATE sensors SET collect=? WHERE client_id=? $excl $fSql",
                array_merge([$nc, $cid], $fPar))->rowCount();
            bump_rev($cid);
            audit($u['name'], 'filter_' . $nc, "client #$cid ($n db)");
            flash(t('act.bulk_filtered', $n));
        }
        $tabBack = isset($_POST['tab']) && in_array($_POST['tab'], ['pending', 'yes', 'no'], true)
            ? '?tab=' . $_POST['tab'] : '';
        redirect(url('/clients/' . $cid) . $tabBack);
    }
    $rows = qall('SELECT id FROM sensors WHERE client_id=?', [$cid]);
    $changed = false;
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        $coll = $_POST['coll'][$id] ?? null;
        if (!in_array($coll, ['yes', 'no', 'pending'], true)) {
            continue;
        }
        $store = isset($_POST['store'][$id]) ? 1 : 0;
        $dec = max(0, min(6, (int)($_POST['dec'][$id] ?? 1)));
        $amin = trim((string)($_POST['amin'][$id] ?? ''));
        $amax = trim((string)($_POST['amax'][$id] ?? ''));
        $gk = trim((string)($_POST['gkey'][$id] ?? ''));
        $gl = trim((string)($_POST['glabel'][$id] ?? ''));
        q('UPDATE sensors SET collect=?, store_history=?, decimals=?,
           alm_min=?, alm_max=?, group_key=?, group_label=? WHERE id=? AND client_id=?',
            [$coll, $store, $dec,
             $amin === '' ? null : (float)$amin, $amax === '' ? null : (float)$amax,
             ($gk !== '' && valid_key($gk)) ? $gk : null,
             $gl !== '' ? substr($gl, 0, 100) : null, $id, $cid]);
        $changed = true;
    }
    if ($changed) {
        bump_rev($cid);
    }
    audit($u['name'], 'sensors_save', 'client #' . $cid);
    [$flt] = sensor_filter();
    // Trigger sync from device list: only on UNFILTERED Enabled tab
    // (else hidden-row state unknown and
    // full-replace would wipe them). Checkbox mirrors client last report;
    // on change one full-replace set_triggers queued.
    $trigMsg = '';
    $tabNow = isset($_POST['tab']) && in_array($_POST['tab'], ['pending', 'yes', 'no'], true)
        ? (string)$_POST['tab'] : '';
    if ($tabNow === 'yes' && $flt['kind'] === '' && $flt['dtype'] === '' && $flt['q'] === '') {
        $want = [];
        foreach ((array)($_POST['trig'] ?? []) as $sid => $v) {
            if ((int)$v === 1 && (int)$sid > 0) {
                $want[] = (int)$sid;
            }
        }
        $wantKeys = [];
        if ($want) {
            $in = implode(',', array_fill(0, count($want), '?'));
            foreach (qall("SELECT sensor_key FROM sensors WHERE client_id=? AND collect='yes'
                           AND dtype='bool' AND id IN ($in)", array_merge([$cid], $want)) as $r) {
                $wantKeys[] = (string)$r['sensor_key'];
            }
        }
        $repS = trigger_reported_keys($cid);
        sort($wantKeys);
        sort($repS);
        if ($wantKeys !== $repS) {
            [$nT, $errT] = enqueue_trigger_set($cid, $want);
            if ($errT !== '') {
                $trigMsg = ' ' . $errT;
            } else {
                $trigMsg = ' ' . t('act.trig_sent', $nT);
                audit($u['name'], 'triggers_set', "client #$cid ($nT db, eszközlista)");
            }
        }
    }
    flash(t('act.saved') . $trigMsg);
    $tabBack = isset($_POST['tab']) && in_array($_POST['tab'], ['pending', 'yes', 'no'], true)
        ? (string)$_POST['tab'] : '';
    $qs = http_build_query(array_filter([
        'tab' => $tabBack,
        'f_kind' => $flt['kind'], 'f_dtype' => $flt['dtype'], 'f_q' => $flt['q'],
    ]));
    redirect(url('/clients/' . $cid) . ($qs !== '' ? '?' . $qs : ''));
}

/** Trigger matrix: all enabled bool sensors; checkbox mirrors client
 * LAST report. Submit = one full-replace command. */
function pg_triggers(array $u, int $cid): void
{
    $c = q1('SELECT * FROM clients WHERE id=?', [$cid]);
    if (!$c) {
        flash(t('act.noclient'));
        redirect(url('/dashboard'));
    }
    $sensors = qall("SELECT * FROM sensors WHERE client_id=? AND collect='yes' AND dtype='bool'
                     ORDER BY kind, group_key, sort_no, label", [$cid]);
    $rep = trigger_reported_keys($cid);
    $at = trigger_reported_at($cid);
    $b = '<p>' . ($at !== null
            ? t('trig.last_sent', '<b>' . e(fmt_dt($at)) . '</b>')
            : t('trig.never_sent')) . '.</p>';
    $b .= '<form method="post" action="' . e(url('/clients/' . $cid . '/triggers')) . '">'
        . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
        . '<div class="wrap"><table><tr><th>' . t('trig.col_sensor') . '</th><th>' . t('trig.col_group') . '</th><th>' . t('trig.col_type') . '</th>'
        . '<th>' . t('trig.col_watch') . '</th></tr>';
    foreach ($sensors as $s) {
        $lab = trim((string)$s['label']) !== '' ? (string)$s['label'] : (string)$s['sensor_key'];
        $b .= '<tr><td>' . e($lab) . '<br><small>' . e($s['sensor_key']) . '</small></td>'
            . '<td>' . e((trim((string)($s['group_label'] ?? '')) !== ''
                ? (string)$s['group_label'] : (string)($s['group_key'] ?? '—'))) . '</td>'
            . '<td>' . e($s['kind']) . '/bool</td>'
            . '<td><input type="checkbox" name="trig[' . (int)$s['id'] . ']" value="1"'
            . (in_array($s['sensor_key'], $rep, true) ? ' checked' : '') . '></td></tr>';
    }
    $b .= '</table></div>'
        . '<p><small>' . t('trig.note') . '</small></p>'
        . '<button type="submit" name="op" value="save">' . t('trig.save') . '</button> '
        . '<button type="submit" name="op" value="refresh">' . t('trig.refresh') . '</button> '
        . '<a href="' . e(url('/clients/' . $cid)) . '">' . t('btn.back') . '</a></form>';
    if (!$sensors) {
        $b .= '<p>' . t('trig.empty') . '</p>';
    }
    layout(t('trig.title', $c['name']), $u, $b);
}

function act_triggers_save(array $u, int $cid): void
{
    $c = q1('SELECT id FROM clients WHERE id=?', [$cid]);
    if (!$c) {
        flash(t('act.noclient'));
        redirect(url('/dashboard'));
    }
    if (($_POST['op'] ?? '') === 'refresh') {
        enqueue_command($cid, 'request_triggers');
        audit($u['name'], 'triggers_request', 'client #' . $cid);
        flash(t('act.trig_req'));
        redirect(url('/clients/' . $cid . '/triggers'));
    }
    $want = [];
    foreach ((array)($_POST['trig'] ?? []) as $sid => $v) {
        if ((int)$v === 1 && (int)$sid > 0) {
            $want[] = (int)$sid;
        }
    }
    [$n, $err] = enqueue_trigger_set($cid, $want);
    if ($err !== '') {
        flash($err);
        redirect(url('/clients/' . $cid . '/triggers'));
    }
    audit($u['name'], 'triggers_set', "client #$cid ($n db, mátrix, full)");
    flash(t('act.trig_sent', $n));
    redirect(url('/clients/' . $cid . '/triggers'));
}

function act_pending(array $u, int $cid): void
{
    $op = $_POST['op'] ?? '';
    if ($op === 'approve_all') {
        q("UPDATE sensors SET collect='yes' WHERE client_id=? AND collect='pending'", [$cid]);
        bump_rev($cid);
        audit($u['name'], 'pending_all', 'client #' . $cid);
    } else {
        $sid = (int)($_POST['sensor_id'] ?? 0);
        $s = q1("SELECT * FROM sensors WHERE id=? AND client_id=? AND collect='pending'", [$sid, $cid]);
        if ($s) {
            $nc = $op === 'approve' ? 'yes' : 'no';
            q('UPDATE sensors SET collect=? WHERE id=?', [$nc, $sid]);
            bump_rev($cid);
            audit($u['name'], 'pending_' . $op, $s['sensor_key'] . ' #' . $sid);
        }
    }
    flash(t('act.pending_ok'));
    redirect(url('/clients/' . $cid));
}

function act_poll(array $u, int $cid): void
{
    $old = q1('SELECT poll_day_s, poll_night_s FROM clients WHERE id=?', [$cid]);
    $d = max(60, min(86400, (int)($_POST['poll_day_s'] ?? 600)));
    $n = max(60, min(86400, (int)($_POST['poll_night_s'] ?? 1200)));
    q('UPDATE clients SET poll_day_s=?, poll_night_s=? WHERE id=?', [$d, $n, $cid]);
    q('UPDATE sensors SET expected_every_s=? WHERE client_id=?', [max($d, $n), $cid]);

    // Decided: change REACHES client too — changed period sends `set_poll`,
    // THEN sysinfo request where client confirms (poll object) the switch.
    $sent = [];
    if (!$old || (int)$old['poll_day_s'] !== $d) {
        $sent[] = 'day=' . $d;
        enqueue_command($cid, 'set_poll', ['scope' => 'day', 'value_s' => $d]);
    }
    if (!$old || (int)$old['poll_night_s'] !== $n) {
        $sent[] = 'night=' . $n;
        enqueue_command($cid, 'set_poll', ['scope' => 'night', 'value_s' => $n]);
    }
    if ($sent) {
        enqueue_command($cid, 'request_sysinfo');
        audit($u['name'], 'poll_save', "client #$cid $d/$n → set_poll " . implode(' ', $sent)
            . ' + sysinfo-kérés');
        flash(t('act.poll_saved'));
    } else {
        audit($u['name'], 'poll_save', "client #$cid $d/$n (nem változott)");
        flash(t('act.poll_same'));
    }
    redirect(url('/clients/' . $cid));
}

function act_notify(array $u, int $cid): void
{
    $m = $_POST['notify_mode'] ?? 'none';
    if (!in_array($m, ['none', 'mail', 'ntfy'], true)) {
        $m = 'none';
    }
    q('UPDATE clients SET notify_mode=?, notify_target=? WHERE id=?',
        [$m, substr(trim($_POST['notify_target'] ?? ''), 0, 300), $cid]);
    audit($u['name'], 'notify_save', "client #$cid $m");
    flash(t('act.notify_saved'));
    redirect(url('/clients/' . $cid));
}

function act_debug(array $u, int $cid): void
{
    $on = isset($_POST['debug']) && (int)$_POST['debug'] === 1 ? 1 : 0;
    q('UPDATE clients SET debug=? WHERE id=?', [$on, $cid]);
    if ($on === 0) {
        // Disable also deletes old raw files.
        @unlink(data_dir() . '/configs/client_' . $cid . '_config.json');
        @unlink(data_dir() . '/configs/client_' . $cid . '_report.json');
    }
    audit($u['name'], 'debug_' . ($on === 1 ? 'on' : 'off'), 'client #' . $cid);
    flash($on === 1 ? t('act.debug_on') : t('act.debug_off'));
    redirect(url('/clients/' . $cid));
}

function act_cmd(array $u, int $cid): void
{
    $t = $_POST['type'] ?? '';
    $now = now_ts();
    switch ($t) {
        case 'request_config':
        case 'request_sysinfo':
        case 'request_triggers':
            enqueue_command($cid, $t);
            break;
        case 'set_poll_day':
            enqueue_command($cid, 'set_poll',
                ['scope' => 'day', 'value_s' => max(60, min(86400, (int)($_POST['value_s'] ?? 600)))]);
            // After server-side polling change server requests sysinfo,
            // so client confirms the switch worked.
            enqueue_command($cid, 'request_sysinfo');
            break;
        case 'set_poll_night':
            enqueue_command($cid, 'set_poll',
                ['scope' => 'night', 'value_s' => max(60, min(86400, (int)($_POST['value_s'] ?? 1200)))]);
            enqueue_command($cid, 'request_sysinfo');
            break;
        case 'control':
            $s = q1("SELECT * FROM sensors WHERE id=? AND client_id=? AND kind='control'
                     AND collect='yes'", [(int)($_POST['sensor_id'] ?? 0), $cid]);
            if (!$s) {
                flash(t('act.cmd_bad_target'));
                redirect(url('/clients/' . $cid));
            }
            [$params, $perr] = control_params_from_post();
            if ($perr !== null) {
                flash($perr);
                redirect(url('/clients/' . $cid));
            }
            $arg = validate_control_args((string)($_POST['state'] ?? ''), $params, $s['ctl_json'] ?? null);
            if ($arg === null) {
                flash(t('act.cmd_bad_state'));
                redirect(url('/clients/' . $cid));
            }
            enqueue_command($cid, 'control', array_merge(['n' => $s['sensor_key']], $arg), $s['sensor_key']);
            break;
        case 'ota_notice':
        case 'ota_update':
            // Form sends uploaded release list (rel_id); legacy free-text
            // 'version' field still accepted (API/tests).
            $rel = null;
            if ((int)($_POST['rel_id'] ?? 0) > 0) {
                $rel = q1('SELECT * FROM ota_releases WHERE id=?', [(int)$_POST['rel_id']]);
            }
            if (!$rel) {
                $v = substr(trim($_POST['version'] ?? ''), 0, 32);
                $rel = $v !== '' ? q1('SELECT * FROM ota_releases WHERE version=?', [$v]) : null;
            }
            if (!$rel) {
                flash(t('act.cmd_bad_ota'));
                redirect(url('/clients/' . $cid));
            }
            if ($t === 'ota_notice') {
                enqueue_command($cid, 'ota_notice', ['version' => $rel['version']]);
            } else {
                enqueue_command($cid, 'ota_update',
                    ['version' => $rel['version'],
                     'url' => '/ota/' . $rel['client_type'] . '/' . $rel['version']]);
            }
            break;
        default:
            flash(t('act.cmd_unknown'));
            redirect(url('/clients/' . $cid));
    }
    audit($u['name'], 'cmd_enqueue', "client #$cid $t");
    flash(t('act.cmd_queued'));
    redirect(url('/clients/' . $cid));
}

// ----------------------------------------------------------------- keys ---

function pg_keys(array $u, ?string $newKey = null): void
{
    $b = '';
    if ($newKey !== null) {
        $b .= '<p class="warn"><b>' . t('keys.fresh') . '</b><br><code>'
            . e($newKey) . '</code></p>';
    }
    $clients = qall('SELECT * FROM clients ORDER BY name');
    $b .= '<h2>' . t('keys.new') . '</h2><form method="post" action="' . e(url('/keys/issue')) . '">'
        . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
        . '<select name="client_id">';
    foreach ($clients as $c) {
        $b .= '<option value="' . (int)$c['id'] . '">' . e($c['name']) . '</option>';
    }
    $b .= '</select> <input name="note" placeholder="' . t('keys.note_ph') . '"> '
        . '<button type="submit">' . t('keys.issue') . '</button></form>';
    $keys = qall('SELECT k.*, c.name cname FROM api_keys k JOIN clients c ON c.id=k.client_id
                  ORDER BY k.created_at DESC');
    $b .= '<h2>' . t('keys.list') . '</h2><table><tr><th>' . t('keys.client') . '</th><th>' . t('keys.prefix') . '</th><th>' . t('keys.created') . '</th>'
        . '<th>' . t('keys.lastused') . '</th><th>' . t('keys.note') . '</th><th></th></tr>';
    foreach ($keys as $k) {
        $b .= '<tr><td>' . e($k['cname']) . '</td><td><code>szk_' . e($k['prefix']) . '.…</code></td>'
            . '<td>' . e(fmt_dt((int)$k['created_at'])) . '</td>'
            . '<td>' . ($k['last_used_at'] ? e(fmt_dt((int)$k['last_used_at'])) : t('keys.never')) . '</td>'
            . '<td>' . e($k['note']) . '</td><td>'
            . ($k['revoked_at']
                ? t('keys.revoked_state')
                : '<form method="post" action="' . e(url('/keys/' . (int)$k['id'] . '/revoke')) . '">'
                . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
                . '<button type="submit">' . t('keys.revoke') . '</button></form>')
            . '</td></tr>';
    }
    layout(t('nav.keys'), $u, $b . '</table>');
}

function act_key_issue(array $u): string
{
    $cid = (int)($_POST['client_id'] ?? 0);
    $c = q1('SELECT * FROM clients WHERE id=?', [$cid]);
    if (!$c) {
        flash(t('act.noclient'));
        redirect(url('/keys'));
    }
    $prefix = substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(9))), 0, 8);
    if (strlen($prefix) < 8) {
        $prefix = str_pad($prefix, 8, 'x');
    }
    $secret = rand_b64url(32);
    q('INSERT INTO api_keys (client_id, prefix, khash, created_by, created_at, note)
       VALUES (?,?,?,?,?,?)',
        [$cid, $prefix, hash('sha256', $secret), $u['id'], now_ts(),
         substr(trim($_POST['note'] ?? ''), 0, 200)]);
    audit($u['name'], 'key_issue', $c['name'] . ' szk_' . $prefix);
    return 'szk_' . $prefix . '.' . $secret;
}

function act_key_revoke(array $u, int $kid): void
{
    q('UPDATE api_keys SET revoked_at=? WHERE id=? AND revoked_at IS NULL', [now_ts(), $kid]);
    audit($u['name'], 'key_revoke', 'key #' . $kid);
    flash(t('act.key_revoked'));
    redirect(url('/keys'));
}

// ---------------------------------------------------------------- users ---

function pg_users(array $u): void
{
    $rows = qall('SELECT * FROM users ORDER BY name');
    $b = '';
    // One-time token display (after generation, from session — gone on refresh).
    if (!empty($_SESSION['fresh_utoken']) && is_array($_SESSION['fresh_utoken'])) {
        $fr = $_SESSION['fresh_utoken'];
        unset($_SESSION['fresh_utoken']);
        $b .= '<div class="card"><h3>' . t('user.token_h', (string)($fr['name'] ?? '')) . '</h3>'
            . '<p><code>' . e((string)($fr['token'] ?? '')) . '</code></p>'
            . '<p><small>' . t('user.token_note') . '</small></p>';
        if (!empty($fr['qr'])) {
            // QR payload (mobile-app contract, v1): {"v":1,"app","url","token"}.
            // URL is app public root (with path), next to token.
            $payload = json_encode(['v' => 1, 'app' => 'SzenzorKozpont',
                'url' => (string)($fr['url'] ?? ''), 'token' => (string)($fr['token'] ?? '')]);
            $b .= '<script src="' . e(url('/assets/qrcode.js')) . '"></script>'
                . '<div id="qrbox" data-payload="' . e((string)$payload) . '"></div>';
        }
        $b .= '</div>';
    }
    $b .= '<h2>' . t('user.new') . '</h2><form method="post" action="' . e(url('/users/add')) . '">'
        . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
        . '<input name="name" placeholder="' . t('user.name_ph') . '" required> '
        . '<input type="password" name="password" placeholder="' . t('user.pass_ph') . '" required> '
        . '<input name="email" placeholder="' . t('user.email_ph') . '" size="24"> '
        . '<select name="role"><option value="user">user</option><option value="admin">admin</option></select> '
        . '<button type="submit">' . t('user.add') . '</button></form>';
    $b .= '<h2>Felhasználók</h2><table><tr><th>' . t('user.col_name') . '</th><th>' . t('user.col_mail') . '</th><th>' . t('user.col_pass') . '</th><th>' . t('user.col_role') . '</th><th></th><th></th></tr>';
    foreach ($rows as $r) {
        $uid = (int)$r['id'];
        $prot = (int)($r['protected'] ?? 0) === 1;
        // One row = one save: inputs in cells, form in Save-button cell
        // (linked via form attribute).
        $fid = 'usave-' . $uid;
        $b .= '<tr><td>' . e($r['name']) . ($prot ? ' <small>' . t('user.prot') . '</small>' : '') . '</td>';
        $b .= '<td><input form="' . $fid . '" name="email" value="' . e((string)($r['email'] ?? '')) . '" placeholder="—" size="24"></td>';
        if (!$prot || $uid === (int)$u['id']) {
            $b .= '<td><input form="' . $fid . '" type="password" name="password" placeholder="' . t('user.pw_ph') . '" size="12"></td>';
        } else {
            $b .= '<td>—</td>';
        }
        if ($prot) {
            $b .= '<td>' . e($r['role']) . '</td>';
        } else {
            $b .= '<td><select form="' . $fid . '" name="role"><option value="user"'
                . ($r['role'] === 'user' ? ' selected' : '') . '>user</option>'
                . '<option value="admin"'
                . ($r['role'] === 'admin' ? ' selected' : '') . '>admin</option></select></td>';
        }
        $b .= '<td><form id="' . $fid . '" method="post" action="' . e(url('/users/' . $uid . '/save')) . '">'
            . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
            . '<button type="submit">' . t('user.save') . '</button></form></td>';
        $b .= '<td><a href="' . e(url('/users/' . $uid . '/rights')) . '">' . t('user.rights') . '</a> ';
        $b .= '<form method="post" action="' . e(url('/users/' . $uid . '/token')) . '" style="display:inline">'
            . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
            . '<button type="submit" title="' . t('user.token_title') . '">' . t('user.token') . '</button></form> ';
        $b .= '<form method="post" action="' . e(url('/users/' . $uid . '/qr')) . '" style="display:inline">'
            . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
            . '<button type="submit" class="ghost" title="' . t('user.qr_title') . '">' . t('user.qr') . '</button></form> ';
        if (!$prot && $uid !== (int)$u['id']) {
            $b .= '<form method="post" action="' . e(url('/users/' . $uid . '/delete')) . '" '
                . 'style="display:inline" onsubmit="return confirm(\'' . t('user.del_confirm') . '\')">'
                . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
                . '<button type="submit" class="danger">' . t('user.del') . '</button></form>';
        }
        $b .= '</td></tr>';
    }
    layout(t('user.list'), $u, $b . '</table>');
}

/** One client rights table: group headers + device rows, with checkboxes. */
function pg_rights(array $u, int $uid): void
{
    $t = q1('SELECT * FROM users WHERE id=?', [$uid]);
    if (!$t) {
        redirect(url('/users'));
    }
    $clients = qall('SELECT * FROM clients ORDER BY name');
    if (!$clients) {
        layout(t('rights.title', $t['name']), $u, '<p>' . t('rights.no_clients') . '</p>');
    }
    $cid = (int)($_GET['c'] ?? 0);
    $cur = null;
    foreach ($clients as $c) {
        if ((int)$c['id'] === $cid) {
            $cur = $c;
        }
    }
    if ($cur === null) {
        $cur = $clients[0];
        $cid = (int)$cur['id'];
    }

    // Rights: client base + group columns + sensor columns.
    $hasCl = (bool)q1('SELECT 1 x FROM client_acl WHERE user_id=? AND client_id=?', [$uid, $cid]);
    $ga = [];
    foreach (qall('SELECT group_key, can_view, can_control FROM group_acl
                   WHERE user_id=? AND client_id=?', [$uid, $cid]) as $r) {
        $ga[(string)$r['group_key']] = [(int)$r['can_view'], (int)$r['can_control']];
    }
    $sa = [];
    foreach (qall('SELECT sa.sensor_id sid, sa.can_view v, sa.can_control c
                    FROM sensor_acl sa JOIN sensors s ON s.id=sa.sensor_id
                   WHERE sa.user_id=? AND s.client_id=?', [$uid, $cid]) as $r) {
        $sa[(int)$r['sid']] = [(int)$r['v'], (int)$r['c']];
    }
    $fb = [$hasCl ? 1 : 0, 0]; // base right: anything not listed gets this

    // sensors by group (empty group_key last)
    $byG = ['' => []];
    $gl = [];
    foreach (qall("SELECT * FROM sensors WHERE client_id=? AND collect='yes'
                    ORDER BY sort_no, label", [$cid]) as $s) {
        $gk = trim((string)$s['group_key']);
        if ($gk === '') {
            $byG[''][] = $s;
            continue;
        }
        if (!isset($byG[$gk])) {
            $byG[$gk] = [];
        }
        $lb = trim((string)$s['group_label']);
        if ($lb !== '' && !isset($gl[$gk])) {
            $gl[$gk] = $lb;
        }
        $byG[$gk][] = $s;
    }
    $st = static function (array $s, string $gk) use ($sa, $ga, $fb): array {
        $sid = (int)$s['id'];
        if (isset($sa[$sid])) {
            return $sa[$sid];
        }
        if ($gk !== '' && isset($ga[$gk])) {
            return $ga[$gk];
        }
        return $fb;
    };
    $row = static function (array $s, string $gk) use ($st): string {
        $sid = (int)$s['id'];
        $x = $st($s, $gk);
        $lab = trim((string)$s['label']);
        if ($lab === '') {
            $lab = (string)$s['sensor_key'];
        }
        return '<tr class="sen" data-gk="' . e($gk) . '" data-n="' . e($lab . ' ' . $s['sensor_key']) . '">'
            . '<td class="ind">' . e($lab) . '<br><small>' . e($s['sensor_key']) . '</small></td>'
            . '<td class="ctr"><input type="checkbox" class="sv" name="sv[' . $sid . ']" value="1"'
            . ($x[0] ? ' checked' : '') . '></td>'
            . '<td class="ctr"><input type="checkbox" class="sc" name="sc[' . $sid . ']" value="1"'
            . ($x[1] ? ' checked' : '') . '></td></tr>';
    };

    $b = '<form method="post" action="' . e(url('/users/' . $uid . '/rights')) . '">'
        . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
        . '<input type="hidden" name="cid" value="' . $cid . '">'
        . '<div class="toolbar"><label>Kliens: <select id="aclclient">';
    foreach ($clients as $c) {
        $b .= '<option value="' . (int)$c['id'] . '"' . ((int)$c['id'] === $cid ? ' selected' : '') . '>'
            . e($c['name']) . '</option>';
    }
    $b .= '</select></label>'
        . '<label>' . t('rights.filter') . ' <input type="search" id="aclq" placeholder="' . t('rights.filter_ph') . '" '
        . 'autocomplete="off"></label>'
        . '<label title="' . t('rights.bulk_title') . '"><input type="checkbox" id="aclallv"> '
        . t('rights.view_all') . '</label>'
        . '<label title="' . t('rights.bulk_title') . '"><input type="checkbox" id="aclallc"> '
        . t('rights.ctrl_all') . '</label>'
        . '<span class="pill" id="aclcnt">0</span></div>';
    if ($t['role'] === 'admin') {
        $b .= '<p class="small"><i>' . t('rights.admin_note') . '</i></p>';
    }

    $gkeys = [];
    foreach (array_keys($byG) as $gk) {
        if ($gk !== '' && $byG[$gk] !== []) {
            $gkeys[] = $gk;
        }
    }
    usort($gkeys, static function (string $a, string $b2) use ($gl): int {
        return strcasecmp($gl[$a] ?? $a, $gl[$b2] ?? $b2);
    });

    $b .= '<table class="acl" id="acltable"><thead><tr><th>' . t('rights.col_sensor') . '</th>'
        . '<th class="ctr">' . t('rights.col_view') . '</th><th class="ctr">' . t('rights.col_ctrl') . '</th></tr></thead><tbody>';
    foreach ($gkeys as $gk) {
        $ch = $byG[$gk];
        $label = $gl[$gk] ?? $gk;
        $gv = true;
        $gc = true;
        foreach ($ch as $s) {
            $x = $st($s, $gk);
            if (!$x[0]) {
                $gv = false;
            }
            if (!$x[1]) {
                $gc = false;
            }
        }
        $b .= '<tr class="grp" data-gk="' . e($gk) . '" data-n="' . e($label . ' ' . $gk) . '">'
            . '<td><button type="button" class="tgl" data-gk="' . e($gk) . '" aria-expanded="true" '
            . 'title="' . t('rights.fold') . '">▾</button> <b>' . e($label) . '</b> <small>' . e($gk)
            . ' · ' . count($ch) . ' szenzor</small></td>'
            . '<td class="ctr"><input type="checkbox" class="gv" data-gk="' . e($gk)
            . '" name="gv[' . $cid . '][' . e($gk) . ']" value="1"'
            . ($gv ? ' checked' : '') . '></td>'
            . '<td class="ctr"><input type="checkbox" class="gc" data-gk="' . e($gk)
            . '" name="gc[' . $cid . '][' . e($gk) . ']" value="1"'
            . ($gc ? ' checked' : '') . '></td></tr>';
        foreach ($ch as $s) {
            $b .= $row($s, $gk);
        }
    }
    if ($byG[''] !== []) {
        $b .= '<tr class="grp nog" data-gk="" data-n="' . t('rights.nogroup') . '">'
            . '<td colspan="3" class="sechead"><b>' . t('rights.nogroup') . '</b> <small>'
            . t('rights.nogroup_n', count($byG[''])) . '</small></td></tr>';
        foreach ($byG[''] as $s) {
            $b .= $row($s, '');
        }
    }
    $b .= '</tbody></table>'
        . '<p class="small">' . t('rights.note') . '</p>'
        . '<button type="submit">' . t('btn.save') . '</button> <a href="' . e(url('/users')) . '">' . t('btn.back') . '</a></form>'
        . '<style>'
        . '.acl td.ctr,.acl th.ctr{text-align:center;width:7.5em}'
        . '.acl tr.grp{background:var(--green-lt)}'
        . '.acl tr.grp td{border-top:2px solid var(--green)}'
        . '.acl td.ind{padding-left:2.1em}'
        . '.acl button.tgl{background:#fff;color:var(--ink);border:1px solid #c3ccc7;'
        . 'padding:0 .4em;margin:0 .5em 0 0;cursor:pointer;font-weight:700}'
        . '.acl tr.fh,.acl tr.fold{display:none}'
        . '.acl .sechead{color:var(--muted)}'
        . '.acl input[type=checkbox]{width:1.05em;height:1.05em;vertical-align:middle}'
        . '.toolbar label{white-space:nowrap}'
        . '#aclq{min-width:13em}'
        . 'p.small{color:var(--muted);font-size:.9em}'
        . '</style>'
        . '<script src="' . e(url('/assets/rights.js')) . '" defer></script>';
    layout(t('rights.title', $t['name']), $u, $b);
}

function act_user_add(array $u): void
{
    $name = trim($_POST['name'] ?? '');
    $pass = (string)($_POST['password'] ?? '');
    $role = ($_POST['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
    $email = trim((string)($_POST['email'] ?? ''));
    if ($name === '' || strlen($name) > 64 || q1('SELECT id FROM users WHERE name=?', [$name])) {
        flash(t('act.user_badname'));
        redirect(url('/users'));
    }
    $min = $role === 'admin' ? 12 : 8;
    if (strlen($pass) < $min) {
        flash(t('act.user_shortpass', $min));
        redirect(url('/users'));
    }
    if (!user_email_ok($email)) {
        flash(t('act.user_badmail'));
        redirect(url('/users'));
    }
    q('INSERT INTO users (name, pass_hash, role, email, created_at) VALUES (?,?,?,?,?)',
        [$name, password_hash($pass, PASSWORD_ARGON2ID), $role, $email !== '' ? $email : null, now_ts()]);
    audit($u['name'], 'user_add', "$name ($role)");
    flash(t('act.user_added'));
    redirect(url('/users'));
}

function act_user_token(array $u, int $uid, bool $qr): void
{
    $t = q1('SELECT * FROM users WHERE id=?', [$uid]);
    if (!$t) {
        redirect(url('/users'));
    }
    $tok = user_token_issue($uid);
    $_SESSION['fresh_utoken'] = ['uid' => $uid, 'name' => $t['name'],
        'token' => $tok, 'qr' => $qr, 'url' => $qr ? base_url() : ''];
    audit($u['name'], $qr ? 'user_qr' : 'user_token', $t['name']);
    redirect(url('/users'));
}

/** Email check: empty (optional) or valid shape. */
function user_email_ok(string $email): bool
{
    return $email === '' || (strlen($email) <= 190
        && filter_var($email, FILTER_VALIDATE_EMAIL) !== false);
}

function act_user_save(array $u, int $uid): void
{
    // One row = one save: email + password (blank = unchanged) + role.
    // Validate everything first, then write (no partial save).
    $t = q1('SELECT * FROM users WHERE id=?', [$uid]);
    if (!$t) {
        redirect(url('/users'));
    }
    $prot = (int)($t['protected'] ?? 0) === 1;
    $email = trim((string)($_POST['email'] ?? ''));
    $pass = (string)($_POST['password'] ?? '');
    $role = isset($_POST['role']) && $_POST['role'] === 'admin' ? 'admin' : 'user';
    if ($prot) {
        $role = $t['role']; // protected: no select in form
    }
    if (!user_email_ok($email)) {
        flash(t('act.user_badmail'));
        redirect(url('/users'));
    }
    if ($prot && $role !== $t['role']) {
        flash(t('act.user_prot_role'));
        redirect(url('/users'));
    }
    if ($pass !== '') {
        if ($prot && $uid !== (int)$u['id']) {
            flash(t('act.user_prot_pw'));
            redirect(url('/users'));
        }
        $min = $role === 'admin' ? 12 : 8;
        if (strlen($pass) < $min) {
            flash(t('act.user_badpass', $min));
            redirect(url('/users'));
        }
    }
    $done = [];
    $newMail = $email !== '' ? $email : null;
    if ($newMail !== ($t['email'] ?? null)) {
        q('UPDATE users SET email=? WHERE id=?', [$newMail, $uid]);
        audit($u['name'], 'user_email', $t['name']);
        $done[] = t('act.user_item_mail');
    }
    if ($pass !== '') {
        q('UPDATE users SET pass_hash=?, locked_until=0 WHERE id=?',
            [password_hash($pass, PASSWORD_ARGON2ID), $uid]);
        audit($u['name'], 'user_pw', $t['name']);
        $done[] = t('act.user_item_pass');
    }
    if ($role !== $t['role']) {
        q('UPDATE users SET role=? WHERE id=?', [$role, $uid]);
        audit($u['name'], 'user_role', $t['name'] . ' -> ' . $role);
        $done[] = t('act.user_item_role');
    }
    flash($done ? t('act.user_saved', implode(', ', $done)) : t('act.user_nochange'));
    redirect(url('/users'));
}

function act_user_delete(array $u, int $uid): void
{
    if ($uid === (int)$u['id']) {
        flash(t('act.user_selfdel'));
        redirect(url('/users'));
    }
    $t = q1('SELECT * FROM users WHERE id=?', [$uid]);
    if (!$t) {
        redirect(url('/users'));
    }
    // Built-in (protected) admin cannot be deleted.
    if ((int)($t['protected'] ?? 0) === 1) {
        flash(t('act.user_protdel'));
        redirect(url('/users'));
    }
    foreach (['client_acl', 'sensor_acl', 'group_acl'] as $tb) {
        q("DELETE FROM $tb WHERE user_id=?", [$uid]);
    }
    q('DELETE FROM mtokens WHERE user_id=?', [$uid]);
    q('DELETE FROM user_tokens WHERE user_id=?', [$uid]);
    q('DELETE FROM users WHERE id=?', [$uid]);
    audit($u['name'], 'user_del', $t['name']);
    flash(t('act.user_deleted'));
    redirect(url('/users'));
}

// ------------------------------------------------------------ settings ---

/** DB size: total + per table (driver-dependent). */
function db_size(): array
{
    if (db_driver() === 'mysql') {
        $db = q1('SELECT DATABASE() db');
        $rows = qall('SELECT table_name n, (data_length+index_length) b
                      FROM information_schema.tables WHERE table_schema=?',
            [$db['db'] ?? '']);
        $tables = [];
        foreach ($rows as $r) {
            $tables[] = ['name' => (string)$r['n'], 'bytes' => (int)$r['b']];
        }
        usort($tables, static fn($a, $b) => $b['bytes'] <=> $a['bytes']);
        return ['total' => array_sum(array_column($tables, 'bytes')), 'tables' => $tables];
    }
    $page = (int)(q1('PRAGMA page_size')['page_size'] ?? 4096);
    $count = (int)(q1('PRAGMA page_count')['page_count'] ?? 0);
    $tables = [];
    try {
        foreach (qall('SELECT name, SUM(pgsize) b FROM dbstat GROUP BY name') as $r) {
            if (str_starts_with((string)$r['name'], 'sqlite_')) {
                continue;
            }
            $tables[] = ['name' => (string)$r['name'], 'bytes' => (int)$r['b']];
        }
        usort($tables, static fn($a, $b) => $b['bytes'] <=> $a['bytes']);
    } catch (PDOException $e) {
        // dbstat unavailable: row-count estimate instead.
        foreach (qall("SELECT name FROM sqlite_master WHERE type='table'
                       AND name NOT LIKE 'sqlite_%'") as $t) {
            $nm = (string)$t['name'];
            $c = q1('SELECT COUNT(*) c FROM "' . str_replace('"', '""', $nm) . '"');
            $tables[] = ['name' => $nm, 'rows' => (int)($c['c'] ?? 0)];
        }
    }
    return ['total' => $page * $count, 'tables' => $tables];
}

/** Schema status: expected tables/columns present (migration applied?).
 * Returns [['name' => ..., 'ok' => bool], ...]. Never throws. */
function schema_status(): array
{
    $out = [];
    $hasTable = static function (string $t): bool {
        try {
            if (db_driver() === 'mysql') {
                $r = q1('SELECT COUNT(*) c FROM information_schema.tables
                         WHERE table_schema=DATABASE() AND table_name=?', [$t]);
                return ((int)($r['c'] ?? 0)) > 0;
            }
            return q1("SELECT name FROM sqlite_master WHERE type='table' AND name=?", [$t]) !== null;
        } catch (PDOException $e) {
            return false;
        }
    };
    $hasCol = static function (string $t, string $c) use ($hasTable): bool {
        try {
            if (!$hasTable($t)) {
                return false;
            }
            if (db_driver() === 'mysql') {
                $r = q1('SELECT COUNT(*) c FROM information_schema.columns
                         WHERE table_schema=DATABASE() AND table_name=? AND column_name=?', [$t, $c]);
                return ((int)($r['c'] ?? 0)) > 0;
            }
            foreach (qall('PRAGMA table_info("' . str_replace('"', '""', $t) . '")') as $col) {
                if ((string)($col['name'] ?? '') === $c) {
                    return true;
                }
            }
            return false;
        } catch (PDOException $e) {
            return false;
        }
    };
    $checks = [
         [t('schema.col_email'), $hasCol('users', 'email')],
         [t('schema.col_protected'), $hasCol('users', 'protected')],
         [t('schema.tab_settings'), $hasTable('settings')],
         [t('schema.tab_tokens'), $hasTable('user_tokens')],
         [t('schema.tab_rules'), $hasTable('alarm_rules')],
         [t('schema.tab_rule_sensors'), $hasTable('alarm_rule_sensors')],
         [t('schema.col_rule_id'), $hasCol('alarms', 'rule_id')],
    ];
    foreach ($checks as [$name, $ok]) {
        $out[] = ['name' => $name, 'ok' => (bool)$ok];
    }
    return $out;
}

function fmt_bytes(int $n): string
{
    if ($n < 1024) {
        return $n . ' B';
    }
    if ($n < 1024 * 1024) {
        return number_format($n / 1024, 1, ',', ' ') . ' KB';
    }
    if ($n < 1024 * 1024 * 1024) {
        return number_format($n / (1024 * 1024), 1, ',', ' ') . ' MB';
    }
    return number_format($n / (1024 * 1024 * 1024), 2, ',', ' ') . ' GB';
}

function pg_settings(array $u): void
{
    $tab = $_GET['tab'] ?? 'db';
    if (!in_array($tab, ['db', 'smtp'], true)) {
        $tab = 'db';
    }
    $t = static function (string $s, string $label) use ($tab): string {
        $qs = $s !== 'db' ? '?tab=' . $s : '';
        return '<a href="' . e(url('/settings') . $qs) . '" class="'
            . ($tab === $s ? 'act' : '') . '">' . $label . '</a>';
    };
    $langOpts = '';
    foreach (lang_list() as $code => $label) {
        $langOpts .= '<option value="' . $code . '"'
            . (lang_current() === $code ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    $b = '<form method="post" action="' . e(url('/settings/lang')) . '">'
        . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
        . '<label>' . t('settings.lang') . ' '
        . '<select name="lang">' . $langOpts . '</select></label> '
        . '<button type="submit">' . t('settings.save'). '</button></form>';
    $b .= '<div class="tabs">' . $t('db', 'Adatbázis') . $t('smtp', 'SMTP') . '</div>';
    if ($tab === 'smtp') {
        $b .= pg_settings_smtp($u);
    } else {
        $b .= pg_settings_db($u);
    }
    layout(t('nav.settings'), $u, $b);
}

function pg_settings_db(array $u): string
{
    $days = (int)(setting('retention_days', '') ?: (cfg()['retention_days'] ?? 30));
    try {
        $dbVer = setting('schema_version', '');
    } catch (Throwable $ignored) {
        $dbVer = '';
    }
    $migOk = is_file(migrate_flag_file())
        || ((string)$dbVer === (string)SCHEMA_VERSION);
    $dataDir = rtrim(cfg()['data_dir'], '/');
    $b = '<h2>' . t('set.system') . '</h2><table>'
        . '<tr><td>' . t('set.php_ver') . '</td><td>' . e(PHP_VERSION) . '</td></tr>'
        . '<tr><td>' . t('set.db') . '</td><td>' . e(db_driver()) . '</td></tr>'
        . '<tr><td>' . t('set.schema_ver') . '</td><td>' . SCHEMA_VERSION . '</td></tr>'
        . '<tr><td>' . t('set.migration') . '</td><td>' . ($migOk ? t('set.mig_ok') : '<b>' . t('set.mig_pending') . '</b>') . '</td></tr>'
        . '<tr><td>' . t('set.data_dir') . '</td><td>' . (is_writable($dataDir)
            ? t('set.writable') : '<b>' . t('set.not_writable') . '</b>') . '</td></tr>'
        . '</table>'
        . '<form method="post" action="' . e(url('/settings/migrate')) . '" style="display:inline">'
        . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
        . '<button type="submit">' . t('set.migrate_btn') . '</button></form>'
        . '<p><small>' . t('set.migrate_note') . '</small></p>';
    $b .= '<h2>' . t('set.retention') . '</h2><form method="post" action="' . e(url('/settings/retention')) . '">'
        . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
        . '<input name="days" value="' . $days . '" size="5" required> ' . t('set.days') . ' '
        . '<button type="submit">' . t('btn.save') . '</button></form>'
        . '<p><small>' . t('set.retention_note') . '</small></p>';
    $sz = db_size();
    $b .= '<h2>' . t('set.db_size', e(fmt_bytes((int)$sz['total']))) . '</h2><table>'
        . '<tr><th>' . t('set.col_table') . '</th><th>' . t('set.col_size') . '</th></tr>';
    foreach ($sz['tables'] as $t) {
        $b .= '<tr><td>' . e($t['name']) . '</td><td>'
            . (isset($t['bytes']) ? e(fmt_bytes((int)$t['bytes'])) : t('set.rows', (int)$t['rows']))
            . '</td></tr>';
    }
    $b .= '</table>';
    $b .= '<h2>' . t('set.cleanup') . '</h2><form method="post" action="' . e(url('/settings/cleanup')) . '" '
        . 'onsubmit="return confirm(\'' . t('set.cleanup_confirm') . '\')">'
        . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
        . '<button type="submit" class="danger">' . t('set.cleanup_btn') . '</button></form>'
        . '<p><small>' . t('set.cleanup_note') . '</small></p>';
    return $b;
}

function act_settings_retention(array $u): void
{
    $days = (int)($_POST['days'] ?? 0);
    if ($days < 1 || $days > 3650) {
        flash(t('set.retention_bad'));
        redirect(url('/settings'));
    }
    setting_set('retention_days', (string)$days);
    audit($u['name'], 'settings_retention', $days . ' nap');
    flash(t('set.retention_saved'));
    redirect(url('/settings'));
}

function act_settings_cleanup(array $u): void
{
    [$a, $b, $done] = job_cleanup();
    audit($u['name'], 'cleanup', t('set.cleanup_done', $a, $b));
    flash(t('set.cleanup_done', $a, $b)
        . ($done ? '' : t('set.cleanup_partial')));
    redirect(url('/settings'));
}

function act_settings_migrate(array $u): void
{
    try {
        $errs = migrate_now();
    } catch (Throwable $e) {
        audit($u['name'], 'migrate_fail', substr($e->getMessage(), 0, 200));
        flash(t('set.migrate_err', $e->getMessage()));
        redirect(url('/settings'));
    }
    if ($errs) {
        audit($u['name'], 'migrate_fail', substr((string)$errs[0], 0, 200));
        flash(t('set.migrate_partial', count($errs), $errs[0]));
    } else {
        audit($u['name'], 'migrate', t('set.migrate_manual', SCHEMA_VERSION));
        flash(t('set.migrate_ok', SCHEMA_VERSION));
    }
    redirect(url('/settings'));
}

function pg_settings_smtp(array $u): string
{
    $c = smtp_config();
    $b = '<h2>' . t('set.smtp') . '</h2><form method="post" action="' . e(url('/settings/smtp')) . '">'
        . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
        . '<table>'
        . '<tr><td>' . t('set.smtp_host') . '</td><td><input name="host" value="' . e($c['host']) . '" size="30" required></td></tr>'
        . '<tr><td>' . t('set.smtp_port') . '</td><td><input name="port" value="' . (int)$c['port'] . '" size="6" required></td></tr>'
        . '<tr><td>' . t('set.smtp_user') . '</td><td><input name="user" value="' . e($c['user']) . '" size="30"></td></tr>'
        . '<tr><td>' . t('set.smtp_pass') . '</td><td><input type="password" name="pass" size="30" placeholder="'
        . ($c['pass'] !== '' ? t('set.smtp_pass_saved') : '') . '"></td></tr>'
        . '<tr><td>' . t('set.smtp_from') . '</td><td><input name="from" value="' . e($c['from']) . '" size="30"></td></tr>'
        . '<tr><td>' . t('set.smtp_secure') . '</td><td><select name="secure">'
        . '<option value="starttls"' . ($c['secure'] === 'starttls' ? ' selected' : '') . '>' . t('set.secure_starttls') . '</option>'
        . '<option value="ssl"' . ($c['secure'] === 'ssl' ? ' selected' : '') . '>' . t('set.secure_ssl') . '</option>'
        . '<option value="none"' . ($c['secure'] === 'none' ? ' selected' : '') . '>' . t('set.secure_none') . '</option>'
        . '</select></td></tr>'
        . '</table>'
        . '<button type="submit">' . t('btn.save') . '</button></form>';
    $b .= '<h2>' . t('set.smtp_test') . '</h2><form method="post" action="' . e(url('/settings/smtp-test')) . '">'
        . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
        . '<input name="to" placeholder="' . t('set.smtp_test_to') . '" size="30" required> '
        . '<button type="submit">' . t('set.smtp_test_btn') . '</button></form>';
    return $b;
}

function act_settings_smtp(array $u): void
{
    $host = substr(trim((string)($_POST['host'] ?? '')), 0, 190);
    $port = (int)($_POST['port'] ?? 0);
    $user = substr(trim((string)($_POST['user'] ?? '')), 0, 190);
    $pass = (string)($_POST['pass'] ?? '');
    $from = trim((string)($_POST['from'] ?? ''));
    $secure = (string)($_POST['secure'] ?? 'starttls');
    if ($host === '' || $port < 1 || $port > 65535
        || !in_array($secure, ['starttls', 'ssl', 'none'], true)
        || ($from !== '' && filter_var($from, FILTER_VALIDATE_EMAIL) === false)) {
        flash(t('set.smtp_bad'));
        redirect(url('/settings?tab=smtp'));
    }
    setting_set('smtp_host', $host);
    setting_set('smtp_port', (string)$port);
    setting_set('smtp_user', $user);
    if ($pass !== '') {
        setting_set('smtp_pass', $pass);
    }
    setting_set('smtp_from', $from);
    setting_set('smtp_secure', $secure);
    audit($u['name'], 'settings_smtp', $host . ':' . $port);
    flash(t('set.smtp_saved'));
    redirect(url('/settings?tab=smtp'));
}

function act_settings_lang(array $u): void
{
    $lang = (string)($_POST['lang'] ?? '');
    if (!isset(lang_list()[$lang])) {
        $lang = 'hu';
    }
    setting_set('lang', $lang);
    lang_set($lang, true);
    audit($u['name'], 'settings_lang', $lang);
    flash(t('settings.saved'));
    redirect(url('/settings'));
}

function act_settings_smtp_test(array $u): void
{
    $to = trim((string)($_POST['to'] ?? ''));
    if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
        flash(t('set.smtp_bad_to'));
        redirect(url('/settings?tab=smtp'));
    }
    $ok = smtp_send($to, t('set.smtp_test_subject'),
        t('set.smtp_test_body', fmt_dt(now_ts())),
        $err);
    audit($u['name'], 'smtp_test', $to . ($ok ? ' OK' : ' HIBA'));
    flash($ok ? t('set.smtp_test_sent') : t('set.smtp_test_fail', $err ?? t('err.unknown')));
    redirect(url('/settings?tab=smtp'));
}

function act_rights_save(array $u, int $uid): void
{
    $t = q1('SELECT * FROM users WHERE id=?', [$uid]);
    if (!$t) {
        redirect(url('/users'));
    }
    $cid = (int)($_POST['cid'] ?? 0);
    $cl = q1('SELECT id, name FROM clients WHERE id=?', [$cid]);
    if (!$cl) {
        flash(t('err.bad_client'));
        redirect(url('/users/' . $uid . '/rights'));
    }
    // Checkbox semantics: browser sends checked (1/on), omits unchecked —
    // explicit '0'/empty also counts as off.
    $on = static function ($arr, $key): int {
        if (!is_array($arr) || !array_key_exists($key, $arr)) {
            return 0;
        }
        $v = $arr[$key];
        if (is_array($v)) {
            $v = reset($v);
        }
        $s = is_scalar($v) ? (string)$v : '';
        return ($s !== '' && $s !== '0') ? 1 : 0;
    };
    // Only selected client group-/sensor-rights rewritten, other clients
    // untouched. client_acl (base client view) has no UI here:
    // existing rows kept, none created.
    q('DELETE FROM group_acl WHERE user_id=? AND client_id=?', [$uid, $cid]);
    q('DELETE FROM sensor_acl WHERE user_id=? AND sensor_id IN
       (SELECT id FROM sensors WHERE client_id=?)', [$uid, $cid]);

    $byG = ['' => []];
    foreach (qall("SELECT id, group_key FROM sensors WHERE client_id=? AND collect='yes'
                    ORDER BY sort_no, label", [$cid]) as $s) {
        $gk = trim((string)$s['group_key']);
        if (!isset($byG[$gk])) {
            $byG[$gk] = [];
        }
        $byG[$gk][] = (int)$s['id'];
    }
    $gvp = is_array($_POST['gv'][$cid] ?? null) ? $_POST['gv'][$cid] : [];
    $gcp = is_array($_POST['gc'][$cid] ?? null) ? $_POST['gc'][$cid] : [];

    foreach ($byG as $gk => $ids) {
        if ($ids === []) {
            continue;
        }
        $st = [];
        foreach ($ids as $sid) {
            $v = $on($_POST['sv'] ?? [], $sid);
            $c = $on($_POST['sc'] ?? [], $sid);
            if ($c === 1) {
                $v = 1; // control implies view
            }
            $st[$sid] = [$v, $c];
        }
        $gv = $gc = 0;
        if ($gk !== '') {
            $gv = $on($gvp, $gk);
            $gc = $on($gcp, $gk);
            if ($gc === 1) {
                $gv = 1;
            }
        }
        // Group right saved only if group has rights AND every child shows
        // exactly that state — else per-device rows needed.
        $useGroup = $gk !== '' && ($gv === 1 || $gc === 1);
        if ($useGroup) {
            foreach ($st as $row) {
                if ($row[0] !== $gv || $row[1] !== $gc) {
                    $useGroup = false;
                    break;
                }
            }
        }
        if ($useGroup) {
            q('INSERT INTO group_acl (user_id, client_id, group_key, can_view, can_control)
               VALUES (?,?,?,?,?)', [$uid, $cid, substr($gk, 0, 50), $gv, $gc]);
            continue;
        }
        foreach ($st as $sid => $row) {
            q('INSERT INTO sensor_acl (user_id, sensor_id, can_view, can_control) VALUES (?,?,?,?)',
                [$uid, $sid, $row[0], $row[1]]);
        }
    }
    audit($u['name'], 'rights_save', $t['name'] . ' / ' . $cl['name']);
    flash(t('rights.saved'));
    redirect(url('/users/' . $uid . '/rights?c=' . $cid));
}

// ---------------------------------------------------------------- alarms ---

function pg_alarms(array $u): void
{
    $cl = user_clients($u);
    $ids = array_column($cl, 'id');
    if (!$ids) {
        layout(t('nav.alarms'), $u, '<p>' . t('alarm.noclient') . '</p>');
    }
    $st = $_GET['st'] ?? '';
    if (!in_array($st, ['active', 'cleared'], true)) {
        $st = '';
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $par = $ids;
    $stSql = '';
    if ($st !== '') {
        $stSql = ' AND a.state=?';
        $par[] = $st;
    }
    $rows = qall("SELECT a.*, c.name cname, s.sensor_key skey, s.label slabel,
                         s.last_seen slast, s.expected_every_s sexp,
                         c.poll_day_s pday, c.poll_night_s pnight, c.inactivity_mult mult
                  FROM alarms a JOIN clients c ON c.id=a.client_id
                  LEFT JOIN sensors s ON s.id=a.sensor_id
                  WHERE a.client_id IN ($in)$stSql"
                  // Plain users see only last 7 days.
                  . (!is_admin($u) ? ' AND a.created_at>?' : '')
                  . " ORDER BY (a.state='active') DESC, a.created_at DESC LIMIT 200",
                  !is_admin($u) ? array_merge($par, [now_ts() - 7 * 86400]) : $par);
    // Manual acks (audit) for "how cleared" column.
    $clearedBy = [];
    $cids = [];
    foreach ($rows as $a) {
        if ($a['state'] === 'cleared') {
            $cids[] = (int)$a['id'];
        }
    }
    if ($cids) {
        $in2 = implode(',', array_fill(0, count($cids), '?'));
        $det = array_map(static function ($id): string {
            return 'alarm #' . $id;
        }, $cids);
        foreach (qall("SELECT detail, actor FROM audit_log WHERE action='alarm_clear'
                       AND detail IN ($in2)", $det) as $r) {
            $clearedBy[(string)$r['detail']] = (string)$r['actor'];
        }
    }
    $typeName = ['inactivity' => t('alarm.type_inactivity'), 'min' => t('alarm.type_min'), 'max' => t('alarm.type_max'),
        'battery' => t('alarm.type_battery'), 'stale' => t('alarm.type_stale'), 'binary' => t('alarm.type_binary')];
    $tab = static function (string $s, string $label) use ($st): string {
        $act = $st === $s;
        return '<a href="' . e(url('/alarms') . ($s !== '' ? '?st=' . $s : '')) . '" class="'
            . ($act ? 'act' : '') . '">' . $label . '</a>';
    };
    $b = '<div class="tabs">' . $tab('', t('alarm.tab_all')) . $tab('active', t('alarm.tab_active'))
        . $tab('cleared', t('alarm.tab_cleared')) . '</div>';
    if (is_admin($u)) {
        $b .= '<p><a href="' . e(url('/alarm-rules/add')) . '"><button type="button">' . t('alarm.new') . '</button></a> '
            . '<a href="' . e(url('/alarm-rules')) . '"><button type="button" class="ghost">' . t('alarm.rules') . '</button></a></p>';
    }
    $b .= '<table><tr><th>' . t('alarm.col_client') . '</th><th>' . t('alarm.col_sensor') . '</th><th>' . t('alarm.col_type') . '</th><th>' . t('alarm.col_value') . '</th>'
        . '<th>' . t('alarm.col_created') . '</th><th>' . t('alarm.col_state') . '</th><th>' . t('alarm.col_cleared') . '</th><th></th></tr>';
    foreach ($rows as $a) {
        // User sees only alarms of visible sensors.
        if (!is_admin($u) && $a['sensor_id'] !== null) {
            $s = q1('SELECT * FROM sensors WHERE id=?', [$a['sensor_id']]);
            if (!$s) {
                continue;
            }
            $r = resolve_right($u, (int)$a['client_id'], $s);
            if (!$r['view']) {
                continue;
            }
        }
        $sname = '—';
        if ($a['sensor_id'] !== null) {
            $lab = trim((string)($a['slabel'] ?? ''));
            $key = trim((string)($a['skey'] ?? ''));
            if ($lab !== '' && $key !== '' && $lab !== $key) {
                $sname = e($lab) . ' <small>' . e($key) . '</small>';
            } elseif ($key !== '') {
                $sname = e($key);
            } elseif ($lab !== '') {
                $sname = e($lab);
            } else {
                $sname = '#' . (int)$a['sensor_id'];
            }
        }
        if ($a['type'] === 'inactivity') {
            $exp = $a['sexp'] !== null ? (int)$a['sexp']
                : max((int)$a['pday'], (int)$a['pnight']);
            $mult = max(1, (int)$a['mult']);
            $lim = $a['threshold'] !== null ? (int)$a['threshold'] : $exp * $mult;
            $silent = $a['value_'] !== null ? (int)$a['value_'] : null;
            if ($a['sensor_id'] === null) {
                // Client-level alarm: no single sensor, stored numbers
                // (silence at creation and threshold) tell the story.
                $val = ($silent !== null ? t('alarm.client_silent', $silent . ' s') : t('alarm.client_silent_unk'))
                    . '<br><small>' . t('alarm.thr_silent', $lim) . '</small>';
            } else {
                $val = ($a['slast'] !== null ? t('alarm.last_data', fmt_dt((int)$a['slast'])) : t('alarm.last_data_never'))
                    . '<br><small>' . t('alarm.thr_silent_exp', $lim, $exp, $mult) . '</small>';
            }
        } else {
            $val = e($a['value_'] !== null ? (string)$a['value_'] : '—') . ' / '
                . e($a['threshold'] !== null ? (string)$a['threshold'] : '—');
        }
        $clr = '—';
        if ($a['state'] === 'cleared') {
            $by = $clearedBy['alarm #' . (int)$a['id']] ?? '';
            $clr = ($a['cleared_at'] !== null ? fmt_dt((int)$a['cleared_at']) : '?')
                . '<br><small>' . ($by !== '' ? t('alarm.cleared_by', e($by)) : t('alarm.cleared_auto')) . '</small>';
        }
        $b .= '<tr><td>' . e($a['cname']) . '</td><td>' . $sname . '</td>'
            . '<td>' . e($typeName[$a['type']] ?? (string)$a['type']) . '</td>'
            . '<td>' . $val . '</td>'
            . '<td>' . e(fmt_dt((int)$a['created_at'])) . '</td>'
            . '<td>' . ($a['state'] === 'active' ? '<b>' . t('alarm.state_active') . '</b>' : t('alarm.state_cleared')) . '</td>'
            . '<td>' . $clr . '</td><td>'
            . ($a['state'] === 'active' && is_admin($u)
                ? '<form method="post" action="' . e(url('/alarms/' . (int)$a['id'] . '/clear')) . '">'
                . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
                . '<button type="submit">' . t('alarm.clear_btn') . '</button></form>' : '')
            . '</td></tr>';
    }
    $b .= '</table><p><small>' . t('alarm.clear_note') . '</small></p>';
    layout(t('nav.alarms'), $u, $b);
}

function act_alarm_clear(array $u, int $aid): void
{
    q("UPDATE alarms SET state='cleared', cleared_at=? WHERE id=? AND state='active'",
        [now_ts(), $aid]);
    audit($u['name'], 'alarm_clear', 'alarm #' . $aid);
    flash(t('alarm.cleared_flash'));
    redirect(url('/alarms'));
}

// ------------------------------------------------------------------- ota ---

// ------------------------------------------------------- alarm rules ---

function pg_alarm_rules(array $u): void
{
    $cnt = [];
    foreach (qall('SELECT rule_id rid, COUNT(*) n FROM alarm_rule_sensors GROUP BY rule_id') as $r) {
        $cnt[(int)$r['rid']] = (int)$r['n'];
    }
    $repLabel = ['once' => t('rule.rep_once'), 'hourly' => t('rule.rep_hourly'), 'daily' => t('rule.rep_daily')];
    $b = '<p><a href="' . e(url('/alarm-rules/add')) . '"><button type="button">' . t('alarm.new') . '</button></a> '
        . '<a href="' . e(url('/alarms')) . '"><button type="button" class="ghost">' . t('rule.alarm_list') . '</button></a></p>';
    $b .= '<table><tr><th>' . t('rule.col_name') . '</th><th>' . t('rule.col_kind') . '</th><th>' . t('rule.col_devices') . '</th><th>' . t('rule.col_rep') . '</th>'
        . '<th>' . t('rule.col_notify') . '</th><th>' . t('rule.col_enabled') . '</th><th></th></tr>';
    foreach (qall('SELECT * FROM alarm_rules ORDER BY name') as $r) {
        $rid = (int)$r['id'];
        $nt = $r['notify_mode'] === 'email'
            ? t('rule.notify_email', (string)($r['notify_target'] ?? '')) : t('rule.notify_log');
        $b .= '<tr><td>' . e($r['name']) . '</td>'
            . '<td>' . e(rule_kind_label((string)$r['kind'])) . '</td>'
            . '<td>' . ($cnt[$rid] ?? 0) . '</td>'
            . '<td>' . e($repLabel[$r['rep']] ?? (string)$r['rep']) . '</td>'
            . '<td>' . e($nt) . '</td>'
            . '<td>' . ((int)$r['enabled'] !== 0 ? t('rule.yes') : t('rule.no')) . '</td>'
            . '<td><a href="' . e(url('/alarm-rules/' . $rid . '/edit')) . '">' . t('rule.edit') . '</a> '
            . '<form method="post" action="' . e(url('/alarm-rules/' . $rid . '/toggle')) . '" style="display:inline">'
            . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
            . '<button type="submit" class="ghost">' . ((int)$r['enabled'] !== 0 ? t('rule.off') : t('rule.on')) . '</button></form> '
            . '<form method="post" action="' . e(url('/alarm-rules/' . $rid . '/delete')) . '" '
            . 'style="display:inline" onsubmit="return confirm(\'' . t('rule.del_confirm') . '\')">'
            . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
            . '<button type="submit" class="danger">' . t('dash.delete') . '</button></form></td></tr>';
    }
    $b .= '</table>';
    layout(t('rule.list'), $u, $b);
}

/** Rule-form validation. Returns [errors, cleaned]. */
function rule_validate(array $in, string $creatorEmail): array
{
    $errs = [];
    $name = substr(trim((string)($in['name'] ?? '')), 0, 100);
    if ($name === '') {
        $errs[] = t('rule.err_name');
    }
    $kind = (string)($in['kind'] ?? '');
    if (!in_array($kind, RULE_KINDS, true)) {
        $errs[] = t('rule.err_kind');
    }
    $threshold = null;
    if (in_array($kind, ['max', 'min', 'battery'], true)) {
        $raw = trim((string)($in['threshold'] ?? ''));
        if ($raw === '' && $kind === 'battery') {
            $raw = '15';
        }
        if ($raw === '' || !is_numeric($raw)) {
            $errs[] = t('rule.err_threshold');
        } else {
            $threshold = (float)$raw;
        }
    }
    $hours = null;
    if ($kind === 'stale') {
        $raw = trim((string)($in['hours'] ?? ''));
        if ($raw === '' || !is_numeric($raw) || (float)$raw <= 0 || (float)$raw > 720) {
            $errs[] = t('rule.err_hours');
        } else {
            $hours = (float)$raw;
        }
    }
    $target = null;
    if ($kind === 'binary') {
        $target = (string)($in['target_state'] ?? '');
        if (!in_array($target, ['on', 'off'], true)) {
            $errs[] = t('rule.err_target');
        }
    }
    $repeat = (string)($in['rep'] ?? 'daily');
    if (!in_array($repeat, RULE_REPEATS, true)) {
        $errs[] = t('rule.err_rep');
    }
    $notify = (string)($in['notify_mode'] ?? 'log');
    if (!in_array($notify, ['log', 'email'], true)) {
        $errs[] = t('rule.err_notify');
    }
    $nto = trim((string)($in['notify_target'] ?? ''));
    if ($notify === 'email') {
        if ($nto === '') {
            $nto = $creatorEmail;
        }
        if ($nto === '' || filter_var($nto, FILTER_VALIDATE_EMAIL) === false) {
            $errs[] = t('rule.err_email');
        }
    }
    $sids = [];
    foreach ((array)($in['sensors'] ?? []) as $sid) {
        $sid = (int)$sid;
        if ($sid > 0) {
            $sids[$sid] = true;
        }
    }
    $sids = array_keys($sids);
    if (count($sids) > 200) {
        $errs[] = t('rule.err_max200');
    }
    if ($sids) {
        $marks = implode(',', array_fill(0, count($sids), '?'));
        $ok = qall("SELECT id FROM sensors WHERE id IN ($marks) AND collect='yes'", $sids);
        if (count($ok) !== count($sids)) {
            $errs[] = t('rule.err_sensor');
        }
    } else {
        $errs[] = t('rule.err_pick');
    }
    return [$errs, ['name' => $name, 'kind' => $kind, 'threshold' => $threshold,
        'hours' => $hours, 'target_state' => $target, 'rep' => $repeat,
        'notify_mode' => $notify, 'notify_target' => $nto !== '' ? $nto : null,
        'sids' => $sids, 'enabled' => !empty($in['enabled']) ? 1 : 0]];
}

function pg_alarm_rule_form(array $u, ?array $rule = null): void
{
    $isEdit = $rule !== null;
    $v = static function (string $k, $def = '') use ($rule): string {
        if ($rule !== null && array_key_exists($k, $rule) && $rule[$k] !== null) {
            return (string)$rule[$k];
        }
        return (string)$def;
    };
    $defTarget = trim((string)($u['email'] ?? ''));
    $act = $isEdit ? '/alarm-rules/' . (int)$rule['id'] . '/save' : '/alarm-rules/add';
    $stored = [];
    if ($isEdit) {
        foreach (qall('SELECT s.id, s.client_id AS cid, s.sensor_key AS skey, s.label FROM sensors s
                       JOIN alarm_rule_sensors rs ON rs.sensor_id=s.id
                       WHERE rs.rule_id=? ORDER BY s.sensor_key', [(int)$rule['id']]) as $ss) {
            $stored[] = ['id' => (int)$ss['id'], 'cid' => (int)$ss['cid'],
                         'key' => (string)$ss['skey'], 'label' => (string)($ss['label'] ?? '')];
        }
    }
    $b = '<form method="post" action="' . e(url($act)) . '" id="rule-form">'
        . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
        . '<table>'
        . '<tr><td>' . t('rule.f_name') . '</td><td><input name="name" value="' . e($v('name')) . '" size="40" required></td></tr>'
        . '<tr><td>' . t('rule.f_kind') . '</td><td><select name="kind">'
        . '<option value="max"' . ($v('kind') === 'max' ? ' selected' : '') . '>' . t('rule.kind_max') . '</option>'
        . '<option value="min"' . ($v('kind') === 'min' ? ' selected' : '') . '>' . t('rule.kind_min') . '</option>'
        . '<option value="battery"' . ($v('kind') === 'battery' ? ' selected' : '') . '>' . t('rule.kind_battery') . '</option>'
        . '<option value="stale"' . ($v('kind') === 'stale' ? ' selected' : '') . '>' . t('rule.kind_stale') . '</option>'
        . '<option value="binary"' . ($v('kind') === 'binary' ? ' selected' : '') . '>' . t('rule.kind_binary') . '</option>'
        . '</select></td></tr>'
        . '<tr><td>' . t('rule.f_threshold') . '</td><td><input name="threshold" value="' . e($v('threshold')) . '" size="10" placeholder="' . t('rule.th_ph') . '"></td></tr>'
        . '<tr><td>' . t('rule.f_hours') . '</td><td><input name="hours" value="' . e($v('hours')) . '" size="10" placeholder="' . t('rule.hours_ph') . '"></td></tr>'
        . '<tr><td>' . t('rule.f_target') . '</td><td><select name="target_state">'
        . '<option value="on"' . ($v('target_state', 'on') === 'on' ? ' selected' : '') . '>' . t('rule.tgt_on') . '</option>'
        . '<option value="off"' . ($v('target_state') === 'off' ? ' selected' : '') . '>' . t('rule.tgt_off') . '</option>'
        . '</select></td></tr>'
        . '<tr><td>' . t('rule.f_rep') . '</td><td><select name="rep">'
        . '<option value="once"' . ($v('rep', 'daily') === 'once' ? ' selected' : '') . '>' . t('rule.rep_once') . '</option>'
        . '<option value="hourly"' . ($v('rep', 'daily') === 'hourly' ? ' selected' : '') . '>' . t('rule.rep_hourly') . '</option>'
        . '<option value="daily"' . ($v('rep', 'daily') === 'daily' ? ' selected' : '') . '>' . t('rule.rep_daily') . '</option>'
        . '</select></td></tr>'
        . '<tr><td>' . t('rule.f_notify') . '</td><td><select name="notify_mode">'
        . '<option value="log"' . ($v('notify_mode', 'log') === 'log' ? ' selected' : '') . '>' . t('rule.notify_log') . '</option>'
        . '<option value="email"' . ($v('notify_mode') === 'email' ? ' selected' : '') . '>' . t('rule.notify_email') . '</option>'
        . '</select> <input name="notify_target" value="' . e($v('notify_target', $defTarget)) . '" size="28" placeholder="' . t('rule.notify_ph') . '"></td></tr>'
        . '<tr><td>' . t('rule.f_enabled') . '</td><td><input type="checkbox" name="enabled" value="1"'
        . ((!$isEdit || (int)($rule['enabled'] ?? 1) !== 0) ? ' checked' : '') . '></td></tr>'
        . '</table><h2>' . t('rule.devices') . '</h2>'
        . '<p><label>Kliens: <select id="rule-client">'
        . '<option value="">' . t('rule.pick_client_ph') . '</option>';
    foreach (qall('SELECT id, name FROM clients ORDER BY name') as $cc) {
        $b .= '<option value="' . (int)$cc['id'] . '">' . e($cc['name']) . '</option>';
    }
    $b .= '</select></label> <small id="rule-selinfo" data-sel="' . e(t('rule.sel_n')) . '" data-empty="' . e(t('rule.no_sensors')) . '" data-pick="' . e(t('rule.pick_client')) . '" data-err="' . e(t('rule.load_err')) . '"></small></p>'
        . '<div id="rule-sensors" data-url="' . e(url('/alarm-rules/sensors')) . '"'
        . ' style="max-height:40vh;overflow:auto;border:1px solid var(--line);border-radius:10px;padding:.5em">'
        . '<p><small>' . t('rule.pick_client') . ' '
        . t('rule.multi_note') . '</small></p></div>'
        . '<div id="rule-keep"></div>'
        . '<script type="application/json" id="rule-stored">'
        . e(json_encode($stored, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
        . '</script>'
        . '<p><button type="submit">' . t('btn.save') . '</button> '
        . '<a href="' . e(url('/alarm-rules')) . '">' . t('btn.cancel') . '</a></p></form>';
    layout($isEdit ? t('rule.edit') : t('rule.new'), $u, $b);
}

/** One client collected sensors as JSON (lazy rule-form load). */
function act_alarm_rule_sensors(array $u, int $cid): void
{
    $c = q1('SELECT id FROM clients WHERE id=?', [$cid]);
    if (!$c) {
        json_out(['ok' => false, 'err' => 'no client'], 404);
    }
    $out = [];
    foreach (qall("SELECT id, sensor_key, label FROM sensors
                   WHERE client_id=? AND collect='yes' ORDER BY sensor_key LIMIT 2000", [$cid]) as $s) {
        $out[] = ['id' => (int)$s['id'], 'key' => (string)$s['sensor_key'],
                  'label' => (string)($s['label'] ?? '')];
    }
    json_out(['ok' => true, 'sensors' => $out]);
}

function act_alarm_rule_save(array $u, ?int $rid): void
{    $back = $rid !== null ? '/alarm-rules/' . $rid . '/edit' : '/alarm-rules/add';
    $cur = null;
    if ($rid !== null) {
        $cur = q1('SELECT * FROM alarm_rules WHERE id=?', [$rid]);
        if (!$cur) {
            redirect(url('/alarm-rules'));
        }
    }
    [$errs, $cl] = rule_validate($_POST, trim((string)($u['email'] ?? '')));
    if ($errs) {
        flash(implode(' ', $errs));
        redirect(url($back));
    }
    if ($cur === null) {
        q('INSERT INTO alarm_rules (name, kind, threshold, hours, target_state, rep,
           notify_mode, notify_target, created_by, enabled, created_at)
           VALUES (?,?,?,?,?,?,?,?,?,?,?)',
            [$cl['name'], $cl['kind'], $cl['threshold'], $cl['hours'], $cl['target_state'],
             $cl['rep'], $cl['notify_mode'], $cl['notify_target'], (int)$u['id'],
             $cl['enabled'], now_ts()]);
        $rid = (int)last_id();
        audit($u['name'], 'rule_add', $cl['name'] . ' #' . $rid);
        flash(t('rule.added'));
    } else {
        q('UPDATE alarm_rules SET name=?, kind=?, threshold=?, hours=?, target_state=?,
           rep=?, notify_mode=?, notify_target=?, enabled=? WHERE id=?',
            [$cl['name'], $cl['kind'], $cl['threshold'], $cl['hours'], $cl['target_state'],
             $cl['rep'], $cl['notify_mode'], $cl['notify_target'], $cl['enabled'], $rid]);
        q('DELETE FROM alarm_rule_sensors WHERE rule_id=?', [$rid]);
        audit($u['name'], 'rule_edit', $cl['name'] . ' #' . $rid);
        flash(t('rule.saved'));
    }
    foreach (array_chunk($cl['sids'], 500) as $chunk) {
        $vals = [];
        $par = [];
        foreach ($chunk as $sid) {
            $vals[] = '(?,?)';
            $par[] = $rid;
            $par[] = $sid;
        }
        q('INSERT INTO alarm_rule_sensors (rule_id, sensor_id) VALUES ' . implode(',', $vals), $par);
    }
    redirect(url('/alarm-rules'));
}

function act_alarm_rule_toggle(array $u, int $rid): void
{
    $r = q1('SELECT * FROM alarm_rules WHERE id=?', [$rid]);
    if (!$r) {
        redirect(url('/alarm-rules'));
    }
    q('UPDATE alarm_rules SET enabled=? WHERE id=?', [(int)$r['enabled'] !== 0 ? 0 : 1, $rid]);
    audit($u['name'], 'rule_toggle', '#' . $rid);
    redirect(url('/alarm-rules'));
}

function act_alarm_rule_delete(array $u, int $rid): void
{
    $r = q1('SELECT * FROM alarm_rules WHERE id=?', [$rid]);
    if (!$r) {
        redirect(url('/alarm-rules'));
    }
    q('DELETE FROM alarm_rule_sensors WHERE rule_id=?', [$rid]);
    // History kept but dropped from email digest.
    q('UPDATE alarms SET rule_id=NULL WHERE rule_id=?', [$rid]);
    q('DELETE FROM alarm_rules WHERE id=?', [$rid]);
    audit($u['name'], 'rule_del', $r['name'] . ' #' . $rid);
    flash(t('rule.deleted'));
    redirect(url('/alarm-rules'));
}

function pg_ota(array $u): void
{    $rows = qall('SELECT * FROM ota_releases ORDER BY created_at DESC');
    $b = '<h2>' . t('ota.new') . '</h2><form method="post" action="' . e(url('/ota/upload')) . '" '
        . 'enctype="multipart/form-data">'
        . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
        . t('ota.type') . ' <select name="client_type"><option value="ha">ha</option>'
        . '<option value="esp32">esp32</option><option value="android">android</option></select> '
        . t('ota.version') . ' <input name="version" size="10" placeholder="' . t('ota.version_ph') . '" required> '
        . t('ota.min_from') . ' <input name="min_from" size="10"> '
        . t('ota.variant') . ' <input name="variant" size="10" placeholder="' . t('ota.variant_ph') . '"> '
        . t('ota.channel') . ' <select name="channel"><option value="stable">stable</option>'
        . '<option value="beta">beta</option></select> '
        . t('ota.file') . ' <input type="file" name="bin" required> '
        . '<button type="submit">' . t('ota.upload') . '</button></form>';
    $b .= '<h2>' . t('ota.releases') . '</h2><table><tr><th>' . t('ota.col_type') . '</th><th>' . t('ota.col_version') . '</th><th>' . t('ota.col_min_from') . '</th>'
        . '<th>' . t('ota.col_variant') . '</th><th>' . t('ota.col_channel') . '</th><th>' . t('ota.col_size') . '</th><th></th></tr>';
    foreach ($rows as $r) {
        $b .= '<tr><td>' . e($r['client_type']) . '</td><td>' . e($r['version']) . '</td>'
            . '<td>' . e((string)($r['min_from'] ?? '')) . '</td>'
            . '<td>' . e(isset($r['variant']) && $r['variant'] !== '' ? (string)$r['variant'] : t('ota.empty_variant')) . '</td>'
            . '<td>' . e($r['channel']) . '</td>'
            . '<td>' . (int)$r['size_bytes'] . '</td><td>'
            . '<form method="post" action="' . e(url('/ota/' . (int)$r['id'] . '/delete')) . '" '
            . 'onsubmit="return confirm(\'' . t('user.del_confirm') . '\')">'
            . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
            . '<button type="submit">' . t('user.del') . '</button></form></td></tr>';
    }
    layout(t('ota.title'), $u, $b . '</table>');
}

function act_ota_upload(array $u): void
{
    $type = $_POST['client_type'] ?? '';
    $ver = trim($_POST['version'] ?? '');
    $ch = ($_POST['channel'] ?? 'stable') === 'beta' ? 'beta' : 'stable';
    $minf = trim($_POST['min_from'] ?? '');
    // Strict match: empty variant only to empty-reporting clients,
    // filled only to exact same variant (see ota_pick).
    $variant = trim($_POST['variant'] ?? '');
    if (!in_array($type, ['ha', 'esp32', 'android'], true)
        || !preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', $ver)
        || ($minf !== '' && !preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', $minf))
        || empty($_FILES['bin']) || $_FILES['bin']['error'] !== UPLOAD_ERR_OK) {
        flash(t('ota.bad'));
        redirect(url('/ota'));
    }
    if ($_FILES['bin']['size'] > 8 * 1024 * 1024) {
        flash(t('ota.too_big'));
        redirect(url('/ota'));
    }
    if (q1('SELECT id FROM ota_releases WHERE client_type=? AND version=?', [$type, $ver])) {
        flash(t('ota.dup'));
        redirect(url('/ota'));
    }
    q('INSERT INTO ota_releases (client_type, version, min_from, variant, url_path, size_bytes, channel, created_at)
       VALUES (?,?,?,?,?,?,?,?)',
        [$type, $ver, $minf === '' ? null : $minf, $variant === '' ? null : substr($variant, 0, 32),
         '', (int)$_FILES['bin']['size'], $ch, now_ts()]);
    $id = last_id();
    $dest = data_dir() . '/ota/' . $id . '.bin';
    if (!move_uploaded_file($_FILES['bin']['tmp_name'], $dest)) {
        q('DELETE FROM ota_releases WHERE id=?', [$id]);
        flash(t('ota.upload_fail'));
        redirect(url('/ota'));
    }
    q('UPDATE ota_releases SET url_path=? WHERE id=?', ['/ota/' . $type . '/' . $ver, $id]);
    audit($u['name'], 'ota_upload', "$type/$ver ($ch" . ($variant === '' ? '' : ", var=$variant") . ')');
    flash(t('ota.uploaded'));
    redirect(url('/ota'));
}

function act_ota_delete(array $u, int $id): void
{
    $r = q1('SELECT * FROM ota_releases WHERE id=?', [$id]);
    if ($r) {
        @unlink(data_dir() . '/ota/' . $id . '.bin');
        q('DELETE FROM ota_releases WHERE id=?', [$id]);
        audit($u['name'], 'ota_delete', $r['client_type'] . '/' . $r['version']);
    }
    flash(t('ota.deleted'));
    redirect(url('/ota'));
}

// ----------------------------------------------------------------- audit ---

function pg_audit(array $u): void
{
    $scope = $_GET['scope'] ?? '';
    if (!in_array($scope, ['api', 'admin'], true)) {
        $scope = '';
    }
    $actor = isset($_GET['actor']) && is_string($_GET['actor'])
        ? substr(trim($_GET['actor']), 0, 64) : '';
    $per = (int)($_GET['per'] ?? 50);
    if (!in_array($per, [20, 50, 100], true)) {
        $per = 50;
    }
    $page = max(1, (int)($_GET['page'] ?? 1));
    $where = '';
    $par = [];
    if ($scope === 'api') {
        $where .= " AND action LIKE 'api!_%' ESCAPE '!'";
    } elseif ($scope === 'admin') {
        $where .= " AND action NOT LIKE 'api!_%' ESCAPE '!'";
    }
    if ($actor !== '') {
        $where .= ' AND actor LIKE ? ESCAPE \'!\'';
        $par[] = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $actor) . '%';
    }
    $total = (int)(q1("SELECT COUNT(*) c FROM audit_log WHERE 1=1 $where", $par)['c'] ?? 0);
    $pages = max(1, (int)ceil($total / $per));
    if ($page > $pages) {
        $page = $pages;
    }
    $off = ($page - 1) * $per;
    $rows = qall("SELECT * FROM audit_log WHERE 1=1 $where ORDER BY id DESC
                  LIMIT $per OFFSET $off", $par);
    // Link builder: keeps filters (scope/actor/per), page overridable.
    $link = static function (array $ov) use ($scope, $actor, $per, $page): string {
        $qs = http_build_query(array_filter([
            'scope' => array_key_exists('scope', $ov) ? $ov['scope'] : $scope,
            'actor' => array_key_exists('actor', $ov) ? $ov['actor'] : $actor,
            'per' => array_key_exists('per', $ov) ? $ov['per'] : $per,
            'page' => array_key_exists('page', $ov) ? $ov['page'] : $page,
        ], static function ($v): bool {
            return $v !== '' && $v !== null;
        }));
        return e(url('/audit') . ($qs !== '' ? '?' . $qs : ''));
    };
    $tab = static function (string $s, string $label) use ($link, $scope): string {
        $act = ($scope === $s) || ($s === '' && $scope === '');
        return '<a href="' . $link(['scope' => $s, 'page' => 1]) . '" class="'
            . ($act ? 'act' : '') . '">' . $label . '</a>';
    };
    $b = '<p><label><input type="checkbox" id="autorefresh" data-secs="5" checked> '
        . t('auto.refresh', 5) . '</label></p>'
        . '<div class="tabs">' . $tab('', t('alarm.tab_all')) . $tab('api', t('audit.traffic'))
        . $tab('admin', t('audit.admin_ops')) . '</div>'
        . '<form method="get" action="' . e(url('/audit')) . '">'
        . '<input type="hidden" name="scope" value="' . e($scope) . '">'
        . '<input type="hidden" name="per" value="' . $per . '">'
        . '<input name="actor" value="' . e($actor) . '" size="20" placeholder="' . t('audit.actor_ph') . '"> '
        . '<button type="submit">' . t('audit.filter_btn') . '</button></form>'
        . '<form method="get" action="' . e(url('/audit')) . '">'
        . '<input type="hidden" name="scope" value="' . e($scope) . '">'
        . '<input type="hidden" name="actor" value="' . e($actor) . '">'
        . '<label>' . t('audit.per') . ' <select name="per">'
        . '<option value="20"' . ($per === 20 ? ' selected' : '') . '>20</option>'
        . '<option value="50"' . ($per === 50 ? ' selected' : '') . '>50</option>'
        . '<option value="100"' . ($per === 100 ? ' selected' : '') . '>100</option>'
        . '</select></label> <button type="submit">' . t('audit.show') . '</button></form>';
    // Pager: Prev | page numbers (current ±2) | Next.
    $pg = '<div class="pager">';
    if ($page > 1) {
        $pg .= '<a href="' . $link(['page' => $page - 1]) . '">' . t('audit.prev') . '</a> ';
    }
    $from = max(1, $page - 2);
    $to = min($pages, $page + 2);
    if ($from > 1) {
        $pg .= '<a href="' . $link(['page' => 1]) . '">1</a> … ';
    }
    for ($p = $from; $p <= $to; $p++) {
        $pg .= $p === $page ? '<b>' . $p . '</b> '
            : '<a href="' . $link(['page' => $p]) . '">' . $p . '</a> ';
    }
    if ($to < $pages) {
        $pg .= '… <a href="' . $link(['page' => $pages]) . '">' . $pages . '</a> ';
    }
    if ($page < $pages) {
        $pg .= '<a href="' . $link(['page' => $page + 1]) . '">' . t('audit.next') . '</a>';
    }
    $pg .= '</div>';
    $first = $total === 0 ? 0 : $off + 1;
    $last = min($total, $off + $per);
    $b .= '<p class="small">' . t('audit.page_of', $page, $pages) . ' · '
        . t('audit.rows_of', $first, $last, $total) . '</p>' . $pg
        . '<div class="wrap"><table><tr><th>' . t('audit.col_time') . '</th><th>' . t('audit.col_actor') . '</th><th>' . t('audit.col_action') . '</th><th>' . t('audit.col_detail') . '</th><th>' . t('audit.col_ip') . '</th></tr>';
    foreach ($rows as $r) {
        $b .= '<tr><td>' . e(fmt_dt((int)$r['ts'])) . '</td><td>' . e($r['actor']) . '</td>'
            . '<td>' . e($r['action']) . '</td><td>' . e(substr((string)$r['detail'], 0, 200)) . '</td>'
            . '<td>' . e($r['ip']) . '</td></tr>';
    }
    layout(t('audit.title'), $u, $b . '</table></div>' . $pg);
}

/** Raw client packet display (debug, admin). */
function act_raw(array $u, int $cid): void
{
    $c = q1('SELECT * FROM clients WHERE id=?', [$cid]);
    if (!$c) {
        redirect(url('/dashboard'));
    }
    $type = $_GET['type'] ?? '';
    if (!in_array($type, ['config', 'report'], true)) {
        $type = 'config';
    }
    $f = data_dir() . '/configs/client_' . $cid . '_' . $type . '.json';
    if (!is_file($f)) {
        layout(
            t('raw.title', $type, $c['name']),
            $u,
            '<p>' . t('raw.nopacket') . '</p>'
            . '<p><a href="' . e(url('/clients/' . $cid)) . '">' . t('btn.back') . '</a></p>'
        );
    }
    $raw = (string)file_get_contents($f);
    $size = strlen($raw);
    if ($size > 100 * 1024) {
        $raw = substr($raw, 0, 100 * 1024) . "\n" . t('raw.truncated');
    }
    // JSON pretty-print if parseable (raw on error).
    $pretty = $raw;
    $nItems = '?';
    $d = json_decode($raw, true);
    if (is_array($d)) {
        $pretty = (string)json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $arr = $d['e'] ?? $d['items'] ?? null;
        if (is_array($arr)) {
            $nItems = (string)count($arr);
        }
    }
    layout(
        t('raw.title', $type, $c['name']),
        $u,
        '<p>' . t('raw.items', $nItems, $size) . ' '
        . '<a href="' . e(url('/clients/' . $cid)) . '">' . t('btn.back') . '</a></p>'
        . '<pre style="white-space:pre-wrap;word-break:break-all">' . e($pretty) . '</pre>'
    );
}
