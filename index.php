<?php
// SzenzorKozpont — front controller + router (at www/ root).

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$base = base_path();
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = substr($uri, strlen($base));
if ($path === '' || $path === false) {
    $path = '/';
}
$seg = array_values(array_filter(explode('/', $path), static function ($s): bool {
    return $s !== '';
}));

// Static UI helpers (CSP bans inline <script>, so JS lives in a
// separate file): /assets/*.js, *.css. No auth (public, UI logic
// only), but served solely from own assets/ dir with
// filename+extension whitelist. This block runs BEFORE segment
// trimming, so the 'assets' prefix survives.
if (($seg[0] ?? '') === 'assets' && $method === 'GET' && count($seg) === 2
    && preg_match('#^[a-z0-9_-]+\.(js|css)$#', $seg[1])) {
    $f = __DIR__ . '/assets/' . $seg[1];
    if (is_file($f)) {
        header('Content-Type: ' . (substr($seg[1], -3) === '.js'
            ? 'application/javascript; charset=utf-8'
            : 'text/css; charset=utf-8'));
        header('Cache-Control: public, max-age=3600');
        readfile($f);
        exit;
    }
    http_response_code(404);
    exit(t('err.file404'));
}

// Fallback: if app is off domain root and base detection slipped
// (e.g. behind alias + try_files), resolve path by suffix too:
// drop prefix up to first known route segment. Our routes' first
// segments are collision-free, so this is safe.
$known = ['api', 'ota', 'm', 'login', 'logout', 'clients', 'keys',
          'users', 'alarms', 'audit', 'export', 'ui', 'sensors', 'client-sensors',
          'dashboard', 'settings', 'alarm-rules'];
while (count($seg) > 0 && !in_array($seg[0], $known, true)) {
    array_shift($seg);
}
$path = '/' . implode('/', $seg);

// ------------------------------------------------------- IoT API (POST) ---
if (($seg[0] ?? '') === 'api') {
    if ($method !== 'POST') {
        json_out(['ok' => false, 'err' => 'method'], 405);
    }
    // Global encoded size cap before decode (anti gzip-bomb).
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 2 * 1024 * 1024) {
        json_out(['ok' => false, 'err' => 'too large'], 413);
    }
    // Auth before body parse: key is header-only, so bad keys fail
    // without JSON decode + handler dispatch cost.
    $r = implode('/', array_slice($seg, 1));
    [$client, $key] = api_auth();
    $body = json_body();
    if ($body === null) {
        json_err('bad json');
    }
    switch ($r) {
        case 'v1/commands/pull':
            api_pull($client, $key, $body);
        case 'v1/report':
            api_report($client, $key, $body);
        case 'v1/config':
            api_config($client, $key, $body);
        case 'v1/sysinfo':
            api_sysinfo($client, $key, $body);
        case 'v1/triggers':
            api_triggers($client, $key, $body);
        case 'v1/ota/check':
            api_ota_check($client, $key, $body);
        default:
            json_out(['ok' => false, 'err' => 'not found'], 404);
    }
}

// ------------------------------------------------- OTA download (GET) ---
if (($seg[0] ?? '') === 'ota' && count($seg) === 3) {
    if ($method !== 'GET') {
        json_out(['ok' => false, 'err' => 'method'], 405);
    }
    api_ota_get($seg[1], $seg[2]);
}

// ---------------------------------------------------------- mobile API ---
if (($seg[0] ?? '') === 'm' && ($seg[1] ?? '') === 'api') {
    $r = implode('/', array_slice($seg, 2));
    if ($r === 'login' && $method === 'POST') {
        mapi_login(json_body());
    }
    if ($r === 'refresh' && $method === 'POST') {
        mapi_refresh();
    }
    if ($r === 'logout' && $method === 'POST') {
        mapi_logout();
    }
    if ($r === 'clients' && $method === 'GET') {
        [$u] = mt_auth('access');
        mapi_clients($u);
    }
    if (preg_match('#^clients/(\d+)/sensors$#', $r, $m) && $method === 'GET') {
        [$u] = mt_auth('access');
        $cid = (int)$m[1];
        if (!user_can_client($u, $cid) && !is_admin($u)) {
            json_out(['ok' => false, 'err' => 'forbidden'], 403);
        }
        foreach (mapi_clients_data($u) as $cl) {
            if ($cl['id'] === $cid) {
                json_out(['ok' => true, 'client' => $cl]);
            }
        }
        json_out(['ok' => false, 'err' => 'not found'], 404);
    }
    if (preg_match('#^sensors/(\d+)/history$#', $r, $m) && $method === 'GET') {
        [$u] = mt_auth('access');
        mapi_history($u, (int)$m[1]);
    }
    if (preg_match('#^sensors/(\d+)/control$#', $r, $m) && $method === 'POST') {
        [$u] = mt_auth('access');
        mapi_control($u, (int)$m[1], json_body());
    }
    json_out(['ok' => false, 'err' => 'not found'], 404);
}

// ---------------------------------------------------------------- web UI ---
web_session();
$user = current_user();

if ($path === '/login') {
    if ($user) {
        redirect(url(is_admin($user) ? '/dashboard' : '/'));
    }
    if ($method === 'POST') {
        if (!csrf_check()) {
            pg_login(t('login.session'), 0);
        }
        $r = web_login(trim($_POST['username'] ?? ''), (string)($_POST['password'] ?? ''));
        if ($r['ok']) {
            $user = current_user();
            redirect(url(is_admin($user) ? '/dashboard' : '/'));
        }
        pg_login($r['msg'], $r['wait']);
    }
    pg_login(null, 0);
}

if ($path === '/logout' && $method === 'POST') {
    if ($user && csrf_check()) {
        audit($user['name'], 'logout');
    }
    web_logout();
    redirect(url('/login'));
}

if (!$user) {
    redirect(url('/login'));
}

// Control (user + admin, CSRF).
if ($path === '/ui/api/control' && $method === 'POST') {
    if (!csrf_check()) {
        http_response_code(403);
        exit(t('err.session'));
    }
    act_control($user);
}

if ($path === '/export' && $method === 'GET') {
    act_export($user);
}

// Sensor history (user + admin; handler checks view right).
if (preg_match('#^/sensors/(\d+)/history$#', $path, $m) && $method === 'GET') {
    pg_sensor_history($user, (int)$m[1]);
}
if (preg_match('#^/sensors/(\d+)/export$#', $path, $m) && $method === 'GET') {
    act_export_sensor($user, (int)$m[1]);
}

// Admin branches.
if (is_admin($user)) {
    if ($path === '/dashboard' && $method === 'GET') {
        pg_dashboard_admin($user);
    }
    if ($path === '/alarm-rules' && $method === 'GET') {
        pg_alarm_rules($user);
    }
    if ($path === '/alarm-rules/add' && $method === 'GET') {
        pg_alarm_rule_form($user);
    }
    if ($path === '/alarm-rules/add' && $method === 'POST' && csrf_check()) {
        act_alarm_rule_save($user, null);
    }
    if ($path === '/alarm-rules/sensors' && $method === 'GET') {
        act_alarm_rule_sensors($user, (int)($_GET['client'] ?? 0));
    }
    if (preg_match('#^/alarm-rules/(\d+)/(edit|save|toggle|delete)$#', $path, $m)) {
        $rid = (int)$m[1];
        if ($m[2] === 'edit' && $method === 'GET') {
            $rule = q1('SELECT * FROM alarm_rules WHERE id=?', [$rid]);
            if (!$rule) {
                redirect(url('/alarm-rules'));
            }
            pg_alarm_rule_form($user, $rule);
        }
        if ($method === 'POST' && csrf_check()) {
            if ($m[2] === 'save') {
                act_alarm_rule_save($user, $rid);
            }
            if ($m[2] === 'toggle') {
                act_alarm_rule_toggle($user, $rid);
            }
            if ($m[2] === 'delete') {
                act_alarm_rule_delete($user, $rid);
            }
        }
    }
    if ($path === '/settings' && $method === 'GET') {
        pg_settings($user);
    }
    if ($path === '/settings/retention' && $method === 'POST' && csrf_check()) {
        act_settings_retention($user);
    }
    if ($path === '/settings/cleanup' && $method === 'POST' && csrf_check()) {
        act_settings_cleanup($user);
    }
    if ($path === '/settings/migrate' && $method === 'POST' && csrf_check()) {
        act_settings_migrate($user);
    }
    if ($path === '/settings/smtp' && $method === 'POST' && csrf_check()) {
        act_settings_smtp($user);
    }
    if ($path === '/settings/smtp-test' && $method === 'POST' && csrf_check()) {
        act_settings_smtp_test($user);
    }
    if ($path === '/settings/lang' && $method === 'POST' && csrf_check()) {
        act_settings_lang($user);
    }
    if ($path === '/clients/add' && $method === 'POST' && csrf_check()) {
        act_client_add($user);
    }
    if (preg_match('#^/clients/(\d+)$#', $path, $m) && $method === 'GET') {
        pg_client_detail($user, (int)$m[1]);
    }
    if (preg_match('#^/clients/(\d+)/raw$#', $path, $m) && $method === 'GET') {
        act_raw($user, (int)$m[1]);
    }
    if (preg_match('#^/clients/(\d+)/triggers$#', $path, $m)) {
        $cid = (int)$m[1];
        if ($method === 'GET') {
            pg_triggers($user, $cid);
        }
        if ($method === 'POST' && csrf_check()) {
            act_triggers_save($user, $cid);
        }
    }
    if (preg_match('#^/clients/(\d+)/(delete|sensors|pending|poll|notify|debug|cmd|rename)$#', $path, $m)
        && $method === 'POST' && csrf_check()) {
        $cid = (int)$m[1];
        switch ($m[2]) {
            case 'delete':
                act_client_delete($user, $cid);
            case 'rename':
                act_client_rename($user, $cid);
            case 'sensors':
                act_sensors_save($user, $cid);
            case 'pending':
                act_pending($user, $cid);
            case 'poll':
                act_poll($user, $cid);
            case 'notify':
                act_notify($user, $cid);
            case 'debug':
                act_debug($user, $cid);
            case 'cmd':
                act_cmd($user, $cid);
        }
    }
    if ($path === '/keys' && $method === 'GET') {
        pg_keys($user);
    }
    if ($path === '/keys/issue' && $method === 'POST' && csrf_check()) {
        $newKey = act_key_issue($user);
        pg_keys($user, $newKey);
    }
    if (preg_match('#^/keys/(\d+)/revoke$#', $path, $m) && $method === 'POST' && csrf_check()) {
        act_key_revoke($user, (int)$m[1]);
    }
    if ($path === '/users' && $method === 'GET') {
        pg_users($user);
    }
    if ($path === '/users/add' && $method === 'POST' && csrf_check()) {
        act_user_add($user);
    }
    if (preg_match('#^/users/(\d+)/(save|delete|rights|token|qr)$#', $path, $m)) {
        $uid = (int)$m[1];
        if ($m[2] === 'rights' && $method === 'GET') {
            pg_rights($user, $uid);
        }
        if ($method === 'POST' && csrf_check()) {
            if ($m[2] === 'save') {
                act_user_save($user, $uid);
            }
            if ($m[2] === 'delete') {
                act_user_delete($user, $uid);
            }
            if ($m[2] === 'rights') {
                act_rights_save($user, $uid);
            }
            if ($m[2] === 'token') {
                act_user_token($user, $uid, false);
            }
            if ($m[2] === 'qr') {
                act_user_token($user, $uid, true);
            }
        }
    }
    if ($path === '/alarms' && $method === 'GET') {
        pg_alarms($user);
    }
    if ($path === '/client-sensors' && $method === 'GET') {
        pg_all_sensors($user);
    }
    if (preg_match('#^/alarms/(\d+)/clear$#', $path, $m) && $method === 'POST' && csrf_check()) {
        act_alarm_clear($user, (int)$m[1]);
    }
    if ($path === '/ota' && $method === 'GET') {
        pg_ota($user);
    }
    if ($path === '/ota/upload' && $method === 'POST' && csrf_check()) {
        act_ota_upload($user);
    }
    if (preg_match('#^/ota/(\d+)/delete$#', $path, $m) && $method === 'POST' && csrf_check()) {
        act_ota_delete($user, (int)$m[1]);
    }
    if ($path === '/audit' && $method === 'GET') {
        pg_audit($user);
    }
    if ($path === '/' && $method === 'GET') {
        redirect(url('/dashboard'));
    }
    http_response_code(404);
    exit('Nincs ilyen oldal.');
}

// Plain user.
if ($path === '/' && $method === 'GET') {
    pg_dashboard($user);
}
if (preg_match('#^/clients/(\d+)$#', $path, $m) && $method === 'GET') {
    pg_client_user($user, (int)$m[1]);
}
if ($path === '/alarms' && $method === 'GET') {
    pg_alarms($user);
}
if ($path === '/client-sensors' && $method === 'GET') {
    pg_all_sensors($user);
}
http_response_code(404);
exit('Nincs ilyen oldal.');
