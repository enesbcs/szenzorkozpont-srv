<?php
// SzenzorKozpont — IoT API-key auth, rate-limit, brute-force protection.

declare(strict_types=1);

const KEY_RE = '/^szk_([A-Za-z0-9]{8})\.([A-Za-z0-9\-_]{20,64})$/';

/** Failed key attempts (IP + prefix, last 1 hour). */
function fail_count(string $ip, string $prefix): int
{
    $r = q1(
        'SELECT COUNT(*) c FROM auth_failures WHERE ip=? AND key_prefix=? AND ts>?',
        [$ip, $prefix, now_ts() - 3600]
    );
    return (int)($r['c'] ?? 0);
}

function log_fail(string $ip, string $prefix): void
{
    q('INSERT INTO auth_failures (ip, key_prefix, user_, ts) VALUES (?,?,NULL,?)',
        [$ip, $prefix, now_ts()]);
}

/** IP block: 50+ failed attempts in 1 hour → 403 for one hour. */
function ip_blocked(string $ip): bool
{
    $r = q1(
        'SELECT COUNT(*) c FROM auth_failures WHERE ip=? AND ts>?',
        [$ip, now_ts() - 3600]
    );
    return ((int)($r['c'] ?? 0)) >= 50;
}

/**
 * API key check. On success returns client row + key row.
 * On failure either delays + logs, or (sometimes) returns decoy OK —
 * then it never returns, always exits.
 */
function api_auth(): array
{
    $ip = client_ip();
    if (ip_blocked($ip)) {
        json_out(['ok' => false, 'err' => 'blocked'], 403);
    }

    $tok = bearer_token();
    $prefix = '';
    if (preg_match('/^szk_([A-Za-z0-9]{8})\./', $tok, $m)) {
        $prefix = $m[1];
    }

    $key = null;
    $client = null;
    if (preg_match(KEY_RE, $tok, $m)) {
        $key = q1(
            'SELECT * FROM api_keys WHERE prefix=? AND revoked_at IS NULL',
            [$m[1]]
        );
        if ($key && hash_equals($key['khash'], hash('sha256', $m[2]))) {
            $client = q1('SELECT * FROM clients WHERE id=?', [$key['client_id']]);
            if (!$client) {
                $key = null;
            }
        } else {
            $key = null;
        }
    }

    if (!$key || !$client) {
        // Incremental backoff: 1s, 2s, 4s … max 30s (+small jitter).
        $n = fail_count($ip, $prefix);
        $wait = min(30, 1 * (2 ** min($n, 5)));
        log_fail($ip, $prefix);
        $wait += rand(0, 500) / 1000;
        usleep((int)($wait * 1000000));
        // Sometimes (15%) decoy OK, logged, no data.
        if (rand(1, 100) <= 15) {
            json_out(['ok' => true]);
        } else {
            json_out(['ok' => false, 'err' => 'bad key'], 401);
        }
    }

    q('UPDATE api_keys SET last_used_at=? WHERE id=?', [now_ts(), $key['id']]);
    return [$client, $key];
}

const USER_TOKEN_RE = '/^sut_([A-Za-z0-9]{8})\.([A-Za-z0-9\-_]{20,64})$/';

/** Mobile user token issue (overwrites previous — only latest valid). Returns display token (secret stored hash-only:
 * name/password cannot be recovered from it). */
function user_token_issue(int $uid): string
{
    $prefix = substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(9))), 0, 8);
    if (strlen($prefix) < 8) {
        $prefix = str_pad($prefix, 8, 'x');
    }
    $secret = rand_b64url(32);
    if (db_driver() === 'mysql') {
        q('INSERT INTO user_tokens (user_id, prefix, thash, created_at) VALUES (?,?,?,?)
           ON DUPLICATE KEY UPDATE prefix=VALUES(prefix), thash=VALUES(thash),
           created_at=VALUES(created_at)', [$uid, $prefix, hash('sha256', $secret), now_ts()]);
    } else {
        q('INSERT INTO user_tokens (user_id, prefix, thash, created_at) VALUES (?,?,?,?)
           ON CONFLICT(user_id) DO UPDATE SET prefix=excluded.prefix, thash=excluded.thash,
           created_at=excluded.created_at', [$uid, $prefix, hash('sha256', $secret), now_ts()]);
    }
    return 'sut_' . $prefix . '.' . $secret;
}

/** User token check (mobile app connect). Returns user row or null. */
function user_token_verify(string $token): ?array
{
    if (!preg_match(USER_TOKEN_RE, trim($token), $m)) {
        return null;
    }
    $row = q1('SELECT * FROM user_tokens WHERE prefix=?', [$m[1]]);
    if (!$row || !hash_equals($row['thash'], hash('sha256', $m[2]))) {
        return null;
    }
    return q1('SELECT * FROM users WHERE id=?', [(int)$row['user_id']]);
}

/** Sliding-window rate-limit. On excess 429 + exit. */
function rate_limit(string $k, int $max, int $window): void
{
    $now = now_ts();
    q('DELETE FROM rate_hits WHERE ts<?', [$now - $window]);
    $r = q1('SELECT COUNT(*) c FROM rate_hits WHERE k=?', [$k]);
    if (((int)($r['c'] ?? 0)) >= $max) {
        json_out(['ok' => false, 'err' => 'rate limited'], 429, ['Retry-After: 60']);
    }
    q('INSERT INTO rate_hits (ip, k, ts) VALUES (?,?,?)', [client_ip(), $k, $now]);
}

/** Daily point quota per client, scaled to expected traffic.
 *
 * Based on actual client settings: (history-storing sensor count)
 * x (day hours / day polling + night hours / night polling),
 * 3x that, min 5000. Normal traffic (polling +
 * trigger reports) fits easily; broken/noisy clients trip it.
 * Counts from UTC midnight (see quota_check).
 */
function quota_limit(int $clientId): int
{
    $c = q1('SELECT poll_day_s, poll_night_s, day_from, day_to FROM clients WHERE id=?', [$clientId]);
    $n = (int)(q1("SELECT COUNT(*) c FROM sensors WHERE client_id=? AND collect='yes'
                   AND store_history=1", [$clientId])['c'] ?? 0);
    $pd = max(60, (int)($c['poll_day_s'] ?? 600));
    $pn = max(60, (int)($c['poll_night_s'] ?? 1200));
    $dh = 15.0;
    if (preg_match('/^(\d{1,2}):(\d{2})$/', (string)($c['day_from'] ?? ''), $m1)
        && preg_match('/^(\d{1,2}):(\d{2})$/', (string)($c['day_to'] ?? ''), $m2)) {
        $from = (int)$m1[1] + (int)$m1[2] / 60;
        $to = (int)$m2[1] + (int)$m2[2] / 60;
        $dh = $to >= $from ? $to - $from : 24 - ($from - $to);
        if ($dh <= 0 || $dh >= 24) {
            $dh = 15.0;
        }
    }
    $perDay = $n * ($dh * 3600 / $pd + (24 - $dh) * 3600 / $pn);
    return max(5000, (int)ceil($perDay * 3));
}

/** Daily point quota check before report. On excess 429 + exit.
 *  Only gates report (pull/config/sysinfo/ota stay free).
 *  Resets at UTC midnight (see $dayStart). */
function quota_check(int $clientId): void
{
    $dayStart = (int)(floor(now_ts() / 86400) * 86400);
    $r = q1(
        'SELECT COUNT(*) c FROM readings_raw WHERE client_id=? AND ts_server>=?',
        [$clientId, $dayStart]
    );
    $used = (int)($r['c'] ?? 0);
    $limit = quota_limit($clientId);
    if ($used >= $limit) {
        json_out(['ok' => false, 'err' => 'quota'], 429, ['Retry-After: 300']);
    }
}

/** Per-key limit scaled to expected polling rate (approx 10x). */
function key_rate_limit(array $client, array $key): void
{
    $minPoll = max(60, min((int)$client['poll_day_s'], (int)$client['poll_night_s']));
    $perHour = (int)(10 * 2 * 3600 / $minPoll);
    rate_limit('key:' . $key['prefix'], max(60, $perHour), 3600);
    rate_limit('ip:' . client_ip(), 30, 60);
}
