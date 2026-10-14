<?php
// SensorKozpont — generic helpers. No framework dependency.

declare(strict_types=1);

function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function now_ts(): int
{
    return time();
}

function json_out(array $data, int $code = 200, array $headers = []): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    foreach ($headers as $h) {
        header($h);
    }
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function json_err(string $msg, int $code = 400): void
{
    json_out(['ok' => false, 'err' => $msg], $code);
}

/** Decoded request body cap: covers MAX_CONFIG_BODY (512 KiB) + headroom. */
const DECODED_MAX = 1048576;

/** Encoded request body cap (as received, gzip included). */
const ENCODED_MAX = 2097152;

/** [encoded, decoded] request body pair, read once. Oversize decoded exits 413. */
function raw_body_pair(): array
{
    static $pair = null;
    if ($pair !== null) {
        return $pair;
    }
    // Pre-read gate: declared length checked before any read (all callers).
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > ENCODED_MAX) {
        json_err('too large', 413);
    }
    // Bounded read: +1 byte proves overflow even with missing/lying
    // length (e.g. chunked encoding) — never hold more than cap+1.
    $raw = (string)file_get_contents('php://input', false, null, 0, ENCODED_MAX + 1);
    if (strlen($raw) > ENCODED_MAX) {
        json_err('too large', 413);
    }
    $dec = $raw;
    $enc = $_SERVER['HTTP_CONTENT_ENCODING'] ?? '';
    if (stripos($enc, 'gzip') !== false && $raw !== '') {
        // Incremental inflate: output bounded, no full-bomb allocation.
        $ctx = function_exists('inflate_init') ? @inflate_init(ZLIB_ENCODING_GZIP) : false;
        if ($ctx !== false) {
            $dec = '';
            $off = 0;
            $len = strlen($raw);
            $ok = true;
            while ($off < $len) {
                $chunk = substr($raw, $off, 65536);
                $off += 65536;
                $part = ($off >= $len)
                    ? @inflate_add($ctx, $chunk, ZLIB_FINISH)
                    : @inflate_add($ctx, $chunk, ZLIB_NO_FLUSH);
                if ($part === false) {
                    $ok = false;
                    break;
                }
                $dec .= $part;
                if (strlen($dec) > DECODED_MAX) {
                    json_err('too large', 413);
                }
            }
            if (!$ok) {
                $dec = $raw; // corrupt gzip: plain path fails as bad json
            }
        }
    }
    if (strlen($dec) > DECODED_MAX) {
        json_err('too large', 413);
    }
    return $pair = [$raw, $dec];
}

/** Raw request body, gunzipped if gzipped. */
function raw_body(): string
{
    return raw_body_pair()[1];
}

/** Raw request body as received (still gzipped if gzipped). */
function raw_body_encoded(): string
{
    return raw_body_pair()[0];
}

/** Decode JSON body to assoc array; null on error. */
function json_body(): ?array
{
    $raw = raw_body();
    if ($raw === '') {
        return [];
    }
    $d = json_decode($raw, true);
    return is_array($d) ? $d : null;
}

function bearer_token(): string
{
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (stripos($h, 'Bearer ') === 0) {
        return trim(substr($h, 7));
    }
    // Some nginx/PHP setups drop HTTP_AUTHORIZATION:
    // forwarded by nginx template.
    $h = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (stripos($h, 'Bearer ') === 0) {
        return trim(substr($h, 7));
    }
    return '';
}

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/** Name rule for sensor_key / group_key fields. */
function valid_key(?string $s): bool
{
    return is_string($s) && $s !== '' && strlen($s) <= 50
        && preg_match('/^[a-zA-Z0-9_.\-]+$/', $s) === 1;
}

/** Semver compare: -1 if $a < $b, 0 if equal, 1 if $a > $b. */
function semver_cmp(string $a, string $b): int
{
    $pa = array_map('intval', explode('.', trim($a)));
    $pb = array_map('intval', explode('.', trim($b)));
    for ($i = 0; $i < max(count($pa), count($pb)); $i++) {
        $x = $pa[$i] ?? 0;
        $y = $pb[$i] ?? 0;
        if ($x < $y) {
            return -1;
        }
        if ($x > $y) {
            return 1;
        }
    }
    return 0;
}

/** Secure random base64url string (API keys, tokens). */
function rand_b64url(int $bytes): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

/** CSRF token handling (web session). */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_check(): bool
{
    $t = $_POST['csrf'] ?? '';
    return is_string($t) && $t !== '' && isset($_SESSION['csrf'])
        && hash_equals((string)$_SESSION['csrf'], $t);
}

function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

/** Short relative time ("5m ago") from epoch delta. Language-aware. */
function ago_hu(int $ts, int $now): string
{
    $d = $now - $ts;
    if ($d < 0) {
        return t('ago.now');
    }
    if ($d < 60) {
        return t('ago.s', $d);
    }
    if ($d < 3600) {
        return t('ago.m', (int)($d / 60));
    }
    if ($d < 86400) {
        return t('ago.h', (int)($d / 3600));
    }
    return t('ago.d', (int)($d / 86400));
}

/** CSV number: language decimal separator, no thousands space. */
function csv_num(float $v, int $decimals): string
{
    $sep = lang_current() === 'hu' ? ',' : '.';
    return number_format($v, $decimals, $sep, '');
}

function fmt_dt(int $ts): string
{
    // Storage: epoch (UTC). Display: local time (config display_tz).
    static $tz = null;
    if ($tz === null) {
        try {
            $tz = new DateTimeZone(cfg()['display_tz'] ?? 'Europe/Budapest');
        } catch (Throwable $e) {
            $tz = new DateTimeZone('UTC');
        }
    }
    return (new DateTime('@' . $ts))->setTimezone($tz)->format('Y-m-d H:i:s');
}

/** Free storage (MB), human-readable; null/negative = unknown. */
function fmt_mb($mb): string
{
    if (!is_numeric($mb) || (int)$mb < 0) {
        return '—';
    }
    $mb = (int)$mb;
    if ($mb < 1024) {
        return $mb . ' MB';
    }
    $gb = $mb / 1024;
    return ($gb >= 100 ? (string)(int)round($gb) : number_format($gb, 1, ',', ' '))
        . ' GB';
}

/** Client-type (sysinfo extra.ctype) friendly name. */
function client_type_label(?string $t): string
{
    static $names = ['ha' => 'Home Assistant (ha)', 'esp32' => 'ESP32 (esp32)',
        'android' => 'Android (android)'];
    $t = (string)$t;
    if ($t === '') {
        return '—';
    }
    return $names[$t] ?? $t;
}

/** Calibrated value: raw * slope + offset (numbers only). */
function calibrated(?float $v, float $slope, float $offset): ?float
{
    return $v === null ? null : $v * $slope + $offset;
}

// ------------------------------------------------- control-params ---
// RGBW lamps: `control` {n, state} accepts these extra
// params. Same validation everywhere
// (web UI, mobile API, admin command form).

const CTL_COLOR_MODES = ['brightness', 'onoff', 'color_temp', 'hs', 'xy', 'rgb', 'rgbw', 'rgbww', 'white'];
const CTL_HVAC_MODES = ['off', 'heat', 'cool', 'auto', 'dry', 'fan_only'];
const CTL_PARAM_KEYS = ['brightness', 'rgb_color', 'rgbw_color', 'rgbww_color', 'color_temp_kelvin', 'effect',
    'temperature', 'current', 'hvac_mode', 'preset_mode'];
/** Report `p` only (read-back state, not for control). */
const REPORT_PARAM_KEYS = ['hvac_action'];

/** Validate control descriptor (`ctl`) from config entry. Returns JSON or null. */
function validate_ctl($ctl): ?string
{
    if (!is_array($ctl)) {
        return null;
    }
    $type = isset($ctl['type']) && is_string($ctl['type']) ? substr($ctl['type'], 0, 20) : '';
    if ($type === '') {
        return null;
    }
    $out = ['type' => $type];
    if ($type === 'light') {
        $out['brightness'] = !empty($ctl['brightness']);
        $modes = [];
        if (isset($ctl['color_modes']) && is_array($ctl['color_modes'])) {
            foreach ($ctl['color_modes'] as $m) {
                if (is_string($m) && in_array($m, CTL_COLOR_MODES, true) && count($modes) < 10) {
                    $modes[] = $m;
                }
            }
        }
        $out['color_modes'] = array_values(array_unique($modes));
        $eff = [];
        if (isset($ctl['effects']) && is_array($ctl['effects'])) {
            foreach ($ctl['effects'] as $fx) {
                if (is_string($fx) && $fx !== '' && strlen($fx) <= 32 && count($eff) < 10) {
                    $eff[] = $fx;
                }
            }
        }
        if ($eff) {
            $out['effects'] = $eff;
        }
    } elseif ($type === 'climate') {
        $has = false;
        foreach (['temp_min', 'temp_max', 'temp_step'] as $tk) {
            if (isset($ctl[$tk]) && is_numeric($ctl[$tk])) {
                $out[$tk] = (float)$ctl[$tk];
                $has = true;
            }
        }
        if (isset($out['temp_min'], $out['temp_max']) && $out['temp_min'] >= $out['temp_max']) {
            unset($out['temp_min'], $out['temp_max'], $out['temp_step']);
            $has = false;
        }
        $modes = [];
        if (isset($ctl['hvac_modes']) && is_array($ctl['hvac_modes'])) {
            foreach ($ctl['hvac_modes'] as $m) {
                if (is_string($m) && in_array($m, CTL_HVAC_MODES, true) && count($modes) < 8) {
                    $modes[] = $m;
                }
            }
        }
        if ($modes) {
            $out['hvac_modes'] = $modes;
            $has = true;
        }
        $pres = [];
        if (isset($ctl['preset_modes']) && is_array($ctl['preset_modes'])) {
            foreach ($ctl['preset_modes'] as $pr) {
                if (is_string($pr) && $pr !== '' && strlen($pr) <= 32 && count($pres) < 10) {
                    $pres[] = $pr;
                }
            }
        }
        if ($pres) {
            $out['preset_modes'] = $pres;
            $has = true;
        }
        if (!$has) {
            return null; // empty climate descriptor
        }
    }
    $json = json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false || strlen($json) > 500) {
        return null;
    }
    return $json;
}

/** Validate report `p` object. Returns clean array or null. */
function validate_entry_params($p): ?array
{
    if (!is_array($p) || !$p) {
        return null;
    }
    $out = [];
    foreach ($p as $k => $v) {
        if (!in_array($k, CTL_PARAM_KEYS, true) && !in_array($k, REPORT_PARAM_KEYS, true)) {
            continue;
        }
        if ($k === 'brightness') {
            if ((is_int($v) || is_float($v)) && $v >= 0 && $v <= 255) {
                $out[$k] = (int)$v;
            }
        } elseif ($k === 'color_temp_kelvin') {
            if ((is_int($v) || is_float($v)) && $v >= 1000 && $v <= 10000) {
                $out[$k] = (int)$v;
            }
        } elseif ($k === 'temperature' || $k === 'current') {
            if ((is_int($v) || is_float($v)) && $v >= -50 && $v <= 100) {
                $out[$k] = (float)$v;
            }
        } elseif ($k === 'hvac_mode' || $k === 'preset_mode' || $k === 'hvac_action') {
            if (is_string($v) && $v !== '' && strlen($v) <= 32) {
                $out[$k] = $v;
            }
        } elseif ($k === 'effect') {
            if (is_string($v) && $v !== '' && strlen($v) <= 32) {
                $out[$k] = $v;
            }
        } else { // rgb_color / rgbw_color / rgbww_color
            $need = $k === 'rgb_color' ? 3 : 4;
            if (is_array($v) && count($v) === $need) {
                $ok = true;
                $iv = [];
                foreach ($v as $c) {
                    if ((is_int($c) || is_float($c)) && $c >= 0 && $c <= 255) {
                        $iv[] = (int)$c;
                    } else {
                        $ok = false;
                        break;
                    }
                }
                if ($ok) {
                    $out[$k] = $iv;
                }
            }
        }
        if (count($out) >= 6) {
            break;
        }
    }
    if (!$out) {
        return null;
    }
    if (strlen(json_encode($out)) > 300) {
        return null;
    }
    return $out;
}

/**
 * Validate full control command. Returns clean args or null.
 * Extra params dropped for state=off/toggle.
 * Unknown keys ignored (future-proofing); known-but-bad
 * values reject the whole command (no silent partial apply).
 * Climate-ctl: temp and modes checked against descriptor limits.
 */
function validate_control_args(string $state, $params, ?string $ctlJson = null): ?array
{
    $ctl = null;
    if ($ctlJson !== null && $ctlJson !== '') {
        $d = json_decode($ctlJson, true);
        if (is_array($d) && isset($d['type'])) {
            $ctl = $d;
        }
    }
    $isClimate = $ctl !== null && ($ctl['type'] ?? '') === 'climate';
    if ($state === '' && $isClimate) {
        // Thermostat: no ON/OFF — params-only command (target/mode/
        // program); thermostat decides and reports state.
        // Empty params meaningless → rejected.
        if (!is_array($params) || !$params) {
            return null;
        }
        $clean = validate_climate_params($params, $ctl);
        if ($clean === null || !$clean) {
            return null;
        }
        return $clean; // no 'state' key
    }
    if (!in_array($state, ['on', 'off', 'toggle'], true)) {
        return null;
    }
    $arg = ['state' => $state];
    if ($state !== 'on' || !is_array($params) || !$params) {
        return $arg;
    }
    if ($isClimate) {
        $clean = validate_climate_params($params, $ctl);
        if ($clean === null) {
            return null;
        }
        return array_merge($arg, $clean);
    }
    $clean = validate_command_params($params);
    if ($clean === null) {
        return null;
    }
    return array_merge($arg, $clean);
}

/** Validate climate params against ctl descriptor limits. */
function validate_climate_params(array $params, array $ctl): ?array
{
    $out = [];
    foreach ($params as $k => $v) {
        if ($k === 'temperature') {
            if (!is_numeric($v)) {
                return null;
            }
            $t = (float)$v;
            $lo = isset($ctl['temp_min']) ? (float)$ctl['temp_min'] : -50;
            $hi = isset($ctl['temp_max']) ? (float)$ctl['temp_max'] : 100;
            if ($t < $lo || $t > $hi) {
                return null;
            }
            $out[$k] = $t;
        } elseif ($k === 'hvac_mode') {
            if (!is_string($v) || $v === '' || strlen($v) > 32) {
                return null;
            }
            if (!empty($ctl['hvac_modes']) && !in_array($v, $ctl['hvac_modes'], true)) {
                return null;
            }
            $out[$k] = $v;
        } elseif ($k === 'preset_mode') {
            if (!is_string($v) || $v === '' || strlen($v) > 32) {
                return null;
            }
            if (!empty($ctl['preset_modes']) && !in_array($v, $ctl['preset_modes'], true)) {
                return null;
            }
            $out[$k] = $v;
        }
        // other keys not for climate: ignored
        if (count($out) >= 3) {
            break;
        }
    }
    return $out;
}

/** Strict validate_entry_params: bad known key → null. */
function validate_command_params($p): ?array
{
    if (!is_array($p) || !$p) {
        return [];
    }
    $out = [];
    foreach ($p as $k => $v) {
        if (!in_array($k, CTL_PARAM_KEYS, true)) {
            continue; // unknown: passed for future extensibility
        }
        $one = validate_entry_params([$k => $v]);
        if ($one === null || !array_key_exists($k, $one)) {
            return null; // known but bad value → strict reject
        }
        $out[$k] = $one[$k];
        if (count($out) >= 6) {
            break;
        }
    }
    return $out;
}

/** HVAC mode/state label (unknown value: raw). */
function hvac_label(string $v): string
{
    static $known = ['off', 'heat', 'heating', 'cool', 'cooling', 'dry', 'drying',
        'fan_only', 'fan', 'auto', 'idle'];
    return in_array($v, $known, true) ? t('hvac.' . $v) : $v;
}

/** Display suffix for stored extra params. */
function format_extra(?string $extraJson, string $unit = ''): string
{
    if ($extraJson === null || $extraJson === '') {
        return '';
    }
    $p = json_decode($extraJson, true);
    if (!is_array($p) || !$p) {
        return '';
    }
    $num = static function ($v) use ($unit): string {
        $s = number_format((float)$v, 1, ',', '');
        return $unit !== '' ? $s . ' ' . $unit : $s;
    };
    $parts = [];
    if (isset($p['temperature'])) {
        $parts[] = t('ctl.d_target', $num($p['temperature']));
    }
    if (isset($p['current'])) {
        $parts[] = t('ctl.d_cur', $num($p['current']));
    }
    if (isset($p['hvac_mode']) && is_string($p['hvac_mode'])) {
        $parts[] = t('ctl.d_mode', hvac_label($p['hvac_mode']));
    }
    if (isset($p['hvac_action']) && is_string($p['hvac_action'])) {
        $parts[] = t('ctl.d_state', hvac_label($p['hvac_action']));
    }
    if (isset($p['preset_mode']) && is_string($p['preset_mode'])) {
        $parts[] = 'preset:' . $p['preset_mode'];
    }
    if (isset($p['brightness'])) {
        $parts[] = (int)round($p['brightness'] / 255 * 100) . '%';
    }
    foreach ([['rgb_color', 'RGB'], ['rgbw_color', 'RGBW'], ['rgbww_color', 'RGBWW']] as [$k, $label]) {
        if (isset($p[$k]) && is_array($p[$k])) {
            $parts[] = $label . '(' . implode(',', array_map('intval', $p[$k])) . ')';
        }
    }
    if (isset($p['color_temp_kelvin'])) {
        $parts[] = (int)$p['color_temp_kelvin'] . 'K';
    }
    if (isset($p['effect']) && is_string($p['effect'])) {
        $parts[] = 'FX:' . $p['effect'];
    }
    return $parts ? ' · ' . implode(' · ', $parts) : '';
}

/** Color array from "r,g,b[,w]" text (admin/user forms). */
function parse_rgb_text(string $s): ?array
{
    $parts = array_map('trim', explode(',', $s));
    if (count($parts) !== 3 && count($parts) !== 4) {
        return null;
    }
    $out = [];
    foreach ($parts as $c) {
        if (!is_numeric($c) || $c < 0 || $c > 255) {
            return null;
        }
        $out[] = (int)$c;
    }
    return [count($out) === 3 ? 'rgb_color' : 'rgbw_color', $out];
}

/** Params from form fields (brightness_pct, color, rgb, temperature, hvac/preset). */
function control_params_from_post(): array
{
    $p = [];
    if (isset($_POST['brightness_pct']) && $_POST['brightness_pct'] !== '') {
        $b = $_POST['brightness_pct'];
        if (!is_numeric($b) || $b < 0 || $b > 100) {
            return [null, t('ctl.e_bright')];
        }
        $p['brightness'] = (int)round($b / 100 * 255);
    }
    if (isset($_POST['color']) && trim((string)$_POST['color']) !== '') {
        // Graphic color picker (#rrggbb) → rgb_color.
        $hc = trim((string)$_POST['color']);
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $hc)) {
            return [null, t('ctl.e_color')];
        }
        $p['rgb_color'] = [hexdec(substr($hc, 1, 2)), hexdec(substr($hc, 3, 2)),
            hexdec(substr($hc, 5, 2))];
    } elseif (isset($_POST['rgb']) && trim((string)$_POST['rgb']) !== '') {
        $r = parse_rgb_text((string)$_POST['rgb']);
        if ($r === null) {
            return [null, t('ctl.e_color2')];
        }
        $p[$r[0]] = $r[1];
    }
    if (isset($_POST['color_temp_kelvin']) && $_POST['color_temp_kelvin'] !== '') {
        $k = $_POST['color_temp_kelvin'];
        if (!is_numeric($k) || $k < 1000 || $k > 10000) {
            return [null, t('ctl.e_temp')];
        }
        $p['color_temp_kelvin'] = (int)$k;
    }
    if (isset($_POST['effect']) && trim((string)$_POST['effect']) !== '') {
        $fx = trim((string)$_POST['effect']);
        if (strlen($fx) > 32) {
            return [null, t('ctl.e_effect')];
        }
        $p['effect'] = $fx;
    }
    if (isset($_POST['temperature']) && trim((string)$_POST['temperature']) !== '') {
        $t = trim((string)$_POST['temperature']);
        if (!is_numeric($t)) {
            return [null, t('ctl.e_target')];
        }
        $p['temperature'] = (float)$t; // range decided by ctl descriptor
    }
    foreach (['hvac_mode', 'preset_mode'] as $mk) {
        if (isset($_POST[$mk]) && trim((string)$_POST[$mk]) !== '') {
            $mv = trim((string)$_POST[$mk]);
            if (strlen($mv) > 32) {
                return [null, t('ctl.e_mode')];
            }
            $p[$mk] = $mv;
        }
    }
    return [$p, null];
}

/** Param summary for audit log. */
function control_arg_summary(array $arg): string
{
    // Thermostat command has no 'state' key (params only).
    $parts = isset($arg['state']) ? [$arg['state']] : [];
    if (isset($arg['temperature'])) {
        $parts[] = (string)$arg['temperature'] . '°';
    }
    if (!empty($arg['hvac_mode'])) {
        $parts[] = hvac_label((string)$arg['hvac_mode']);
    }
    if (!empty($arg['preset_mode'])) {
        $parts[] = 'P:' . $arg['preset_mode'];
    }
    if (isset($arg['brightness'])) {
        $parts[] = (int)round($arg['brightness'] / 255 * 100) . '%';
    }
    foreach (['rgb_color' => 'RGB', 'rgbw_color' => 'RGBW', 'rgbww_color' => 'RGBWW'] as $k => $l) {
        if (isset($arg[$k])) {
            $parts[] = $l . '(' . implode(',', $arg[$k]) . ')';
        }
    }
    if (isset($arg['color_temp_kelvin'])) {
        $parts[] = $arg['color_temp_kelvin'] . 'K';
    }
    if (isset($arg['effect'])) {
        $parts[] = 'FX:' . $arg['effect'];
    }
    return implode(' ', $parts);
}
