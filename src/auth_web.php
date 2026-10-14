<?php
// SzenzorKozpont — web session auth, login protection (incremental backoff).

declare(strict_types=1);

function web_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    // If system session dir not writable, use own data dir
    // (common on shared hosting).
    $sp = session_save_path();
    if ($sp === '' || !is_dir($sp) || !is_writable($sp)) {
        $sp = rtrim(cfg()['data_dir'], '/') . '/sessions';
        if (!is_dir($sp)) {
            mkdir($sp, 0770, true);
        }
        session_save_path($sp);
    }
    session_name('szkid');
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
    // 30-min inactivity timeout.
    if (isset($_SESSION['uid']) && isset($_SESSION['last_act'])
        && now_ts() - (int)$_SESSION['last_act'] > 1800) {
        web_logout();
    }
    $_SESSION['last_act'] = now_ts();
}

function current_user(): ?array
{
    web_session();
    if (empty($_SESSION['uid'])) {
        return null;
    }
    $u = q1('SELECT * FROM users WHERE id=?', [(int)$_SESSION['uid']]);
    if (!$u) {
        web_logout();
    }
    return $u;
}

function is_admin(?array $u): bool
{
    return $u !== null && $u['role'] === 'admin';
}

function web_logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], '', $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/** Failed logins (user+IP, last 1 hour). */
function login_fails(string $user, string $ip): int
{
    $r = q1('SELECT COUNT(*) c FROM auth_failures WHERE user_=? AND ip=? AND ts>?',
        [$user, $ip, now_ts() - 3600]);
    return (int)($r['c'] ?? 0);
}

/**
 * Login. Returns ['ok'=>true] or ['ok'=>false,'wait'=>s].
 * From 3rd failure incremental backoff (5s, 10s, 20s … max 120s),
 * 15-min lock after 20 failures/hour.
 */
function web_login(string $user, string $pass): array
{
    $ip = client_ip();
    $u = q1('SELECT * FROM users WHERE name=?', [$user]);
    // Anti timing-probe: hash even for unknown users.
    $hash = $u ? $u['pass_hash'] : password_hash('dummy', PASSWORD_ARGON2ID);
    $n = login_fails($user, $ip);

    if ($u && (int)$u['locked_until'] > now_ts()) {
        audit($user, 'login_locked', $ip);
        return ['ok' => false, 'wait' => (int)$u['locked_until'] - now_ts(), 'msg' => t('login.locked')];
    }
    if ($n >= 3) {
        $wait = min(120, 5 * (2 ** min($n - 3, 5)));
        sleep($wait);
    }
    // Always generic error (never reveal name vs password failure).
    if (!$u || !password_verify($pass, $hash)) {
        q('INSERT INTO auth_failures (ip, key_prefix, user_, ts) VALUES (?,NULL,?,?)',
            [$ip, $user, now_ts()]);
        audit($user, 'login_fail', $ip);
        if ($u && login_fails($user, $ip) >= 20) {
            q('UPDATE users SET locked_until=? WHERE id=?', [now_ts() + 900, $u['id']]);
        }
        $n2 = login_fails($user, $ip);
        $wait = $n2 >= 3 ? min(120, 5 * (2 ** min($n2 - 3, 5))) : 0;
        return ['ok' => false, 'wait' => $wait, 'msg' => t('login.bad')];
    }
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id'];
    $_SESSION['last_act'] = now_ts();
    q('UPDATE users SET locked_until=0 WHERE id=?', [$u['id']]);
    q('DELETE FROM auth_failures WHERE user_=? AND ip=?', [$user, $ip]);
    audit($user, 'login_ok');
    return ['ok' => true];
}
