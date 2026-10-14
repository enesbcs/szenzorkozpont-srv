<?php
// SzenzorKozpont — mobile API (/m/api/*): short access + long refresh token.

declare(strict_types=1);

const ACCESS_TTL = 900;        // 15 min
const REFRESH_TTL = 30 * 86400; // 30 days

function mt_issue(int $uid, string $kind): string
{
    $tok = rand_b64url(32);
    $ttl = $kind === 'access' ? ACCESS_TTL : REFRESH_TTL;
    q('INSERT INTO mtokens (user_id, kind, thash, expires_at, created_at) VALUES (?,?,?,?,?)',
        [$uid, $kind, hash('sha256', $tok), now_ts() + $ttl, now_ts()]);
    return $tok;
}

function mt_auth(string $kind): array
{
    $tok = bearer_token();
    if ($tok === '') {
        json_out(['ok' => false, 'err' => 'no token'], 401);
    }
    $row = q1('SELECT * FROM mtokens WHERE kind=? AND thash=?', [$kind, hash('sha256', $tok)]);
    if (!$row || (int)$row['expires_at'] < now_ts()) {
        json_out(['ok' => false, 'err' => 'bad token'], 401);
    }
    $u = q1('SELECT * FROM users WHERE id=?', [$row['user_id']]);
    if (!$u) {
        json_out(['ok' => false, 'err' => 'bad token'], 401);
    }
    return [$u, $row];
}

function mapi_login(?array $body): void
{
    rate_limit('ip:' . client_ip(), 30, 60);
    if ($body === null) {
        json_err('bad json');
    }
    $name = (string)($body['username'] ?? '');
    $pass = (string)($body['password'] ?? '');
    $u = q1('SELECT * FROM users WHERE name=?', [$name]);
    $hash = $u ? $u['pass_hash'] : password_hash('dummy', PASSWORD_ARGON2ID);
    $n = login_fails($name, client_ip());
    if ($n >= 3) {
        sleep(min(120, 5 * (2 ** min($n - 3, 5))));
    }
    if (!$u || (int)$u['locked_until'] > now_ts() || !password_verify($pass, $hash)) {
        q('INSERT INTO auth_failures (ip, key_prefix, user_, ts) VALUES (?,NULL,?,?)',
            [client_ip(), $name, now_ts()]);
        audit($name, 'mlogin_fail', client_ip());
        // Same account lockout as web login: 15 min after 20 failures/hour.
        if ($u && login_fails($name, client_ip()) >= 20) {
            q('UPDATE users SET locked_until=? WHERE id=?', [now_ts() + 900, (int)$u['id']]);
        }
        json_out(['ok' => false, 'err' => t('err.badlogin')], 401);
    }
    q('DELETE FROM auth_failures WHERE user_=? AND ip=?', [$name, client_ip()]);
    audit($name, 'mlogin_ok');
    json_out(['ok' => true, 'access' => mt_issue((int)$u['id'], 'access'),
        'refresh' => mt_issue((int)$u['id'], 'refresh'), 'expires_in' => ACCESS_TTL]);
}

function mapi_refresh(): void
{
    [$u, $row] = mt_auth('refresh');
    q('DELETE FROM mtokens WHERE id=?', [$row['id']]);
    json_out(['ok' => true, 'access' => mt_issue((int)$u['id'], 'access'),
        'refresh' => mt_issue((int)$u['id'], 'refresh'), 'expires_in' => ACCESS_TTL]);
}

function mapi_logout(): void
{
    $tok = bearer_token();
    if ($tok !== '') {
        q('DELETE FROM mtokens WHERE thash=?', [hash('sha256', $tok)]);
    }
    json_out(['ok' => true]);
}

function mapi_clients_data(array $u): array
{
    $out = [];
    foreach (user_clients($u) as $c) {
        $ss = user_sensors($u, (int)$c['id']);
        $items = [];
        foreach ($ss as $s) {
            $st = conn_status($s['last_seen'] !== null ? (int)$s['last_seen'] : null,
                $s['expected_every_s'] !== null ? (int)$s['expected_every_s'] : null, now_ts());
            $items[] = [
                'id' => (int)$s['id'], 'key' => $s['sensor_key'], 'label' => $s['label'], 'unit' => $s['unit'],
                'slope' => (float)$s['slope'], 'offset' => (float)$s['offset_'],
                'kind' => $s['kind'], 'dtype' => $s['dtype'],
                'group_key' => $s['group_key'], 'group_label' => $s['group_label'],
                'store_history' => (int)$s['store_history'],
                'value' => $s['last_v_num'] !== null ? (float)$s['last_v_num']
                    : ($s['last_v_bool'] !== null ? (bool)$s['last_v_bool'] : $s['last_v_str']),
                'ts_client' => $s['last_ts_client'] !== null ? (int)$s['last_ts_client'] : null,
                'ts_server' => $s['last_ts_server'] !== null ? (int)$s['last_ts_server'] : null,
                'online' => $st['online'],
                'can_view' => 1, 'can_control' => (int)$s['can_control'],
                // Capability descriptor (lamp brightness/color, HVAC limits) and latest
                // state params (e.g. current brightness/color) — app renders
                // controls from these; `ctl=null` → plain ON/OFF switch.
                'ctl' => json_decode((string)($s['ctl_json'] ?? ''), true) ?: null,
                'extra' => json_decode((string)($s['last_extra'] ?? ''), true) ?: null,
            ];
        }
        $out[] = ['id' => (int)$c['id'], 'name' => $c['name'], 'sensors' => $items];
    }
    return $out;
}

function mapi_clients(array $u): void
{
    json_out(['ok' => true, 'clients' => mapi_clients_data($u)]);
}

function mapi_history(array $u, int $sid): void
{
    $s = q1('SELECT * FROM sensors WHERE id=?', [$sid]);
    if (!$s) {
        json_out(['ok' => false, 'err' => 'not found'], 404);
    }
    $r = resolve_right($u, (int)$s['client_id'], $s);
    if (!$r['view']) {
        json_out(['ok' => false, 'err' => 'forbidden'], 403);
    }
    $range = $_GET['range'] ?? '24h';
    $sec = $range === '7d' ? 7 * 86400 : ($range === '1h' ? 3600 : 86400);
    $h = history_points($sid, $sec, 200);
    foreach ($h['e'] as &$p) {
        $p['n'] = $s['sensor_key'];
    }
    unset($p);
    json_out(['ok' => true, 'key' => $s['sensor_key'], 'bt' => $h['bt'], 'e' => $h['e']]);
}

function mapi_control(array $u, int $sid, ?array $body): void
{
    $s = q1("SELECT * FROM sensors WHERE id=? AND kind='control' AND collect='yes'", [$sid]);
    if (!$s) {
        json_out(['ok' => false, 'err' => 'not found'], 404);
    }
    $r = resolve_right($u, (int)$s['client_id'], $s);
    if (!$r['control']) {
        audit($u['name'], 'control_denied', $s['sensor_key']);
        json_out(['ok' => false, 'err' => 'forbidden'], 403);
    }
    $arg = validate_control_args(
        isset($body['state']) && is_string($body['state']) ? $body['state'] : '',
        $body['params'] ?? [],
        $s['ctl_json'] ?? null
    );
    if ($arg === null) {
        json_err('bad state/params');
    }
    $id = enqueue_command((int)$s['client_id'], 'control',
        array_merge(['n' => $s['sensor_key']], $arg), $s['sensor_key']);
    audit($u['name'], 'control', $s['sensor_key'] . ' → ' . control_arg_summary($arg) . " (#$id)");
    json_out(['ok' => true, 'command_id' => $id, 'status' => 'pending']);
}
