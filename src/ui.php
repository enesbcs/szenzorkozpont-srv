<?php
// SensorKozpont — shared web UI layout, form helpers, sparkline, history.

declare(strict_types=1);

function base_path(): string
{
    // Explicit override from config (sub-URL installs).
    $cfg = cfg()['base_path'] ?? null;
    if (is_string($cfg) && $cfg !== '') {
        return '/' . trim($cfg, '/');
    }
    // Trust SCRIPT_NAME only if it really points to index.php
    // (always under nginx + try_files; not necessarily with php -S router).
    $s = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');
    if ($s === 'index.php') {
        $s = '/' . $s;
    }
    if (substr($s, -10) !== '/index.php') {
        return '';
    }
    $b = rtrim(dirname($s), '/');
    return $b === '' ? '' : $b;
}

function url(string $p): string
{
    return base_path() . $p;
}

/** App public root URL (QR code, sharing). */
function base_url(): string
{
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '' || !preg_match('/^[A-Za-z0-9.\-:]+$/', $host)) {
        $host = 'localhost';
    }
    return ($https ? 'https://' : 'http://') . $host . base_path();
}

function flash(?string $msg = null): ?string
{
    if ($msg !== null) {
        $_SESSION['flash'] = $msg;
        return null;
    }
    $m = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $m;
}

function sec_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header("Content-Security-Policy: default-src 'self'; style-src 'unsafe-inline'; img-src 'self' data:");
}

function layout(string $title, ?array $user, string $body): void
{
    sec_headers();
    $nav = '';
    if ($user) {
        if (is_admin($user)) {
            // Clients/ClientSensors built into Dashboard.
            $nav = '<a href="' . e(url('/dashboard')) . '">' . t('nav.dashboard') . '</a> '
                . '<a href="' . e(url('/keys')) . '">' . t('nav.keys') . '</a> '
                . '<a href="' . e(url('/users')) . '">' . t('nav.users') . '</a> '
                . '<a href="' . e(url('/alarms')) . '">' . t('nav.alarms') . '</a> '
                . '<a href="' . e(url('/ota')) . '">' . t('nav.ota') . '</a> '
                . '<a href="' . e(url('/audit')) . '">' . t('nav.audit') . '</a> '
                . '<a href="' . e(url('/settings')) . '">' . t('nav.settings') . '</a> ';
        } else {
            $nav = '<a href="' . e(url('/')) . '">' . t('nav.dashboard') . '</a> '
                . '<a href="' . e(url('/alarms')) . '">' . t('nav.alarms') . '</a> ';
        }
        $nav .= '<form method="post" action="' . e(url('/logout')) . '" style="display:inline">'
            . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
            . '<button type="submit">' . t('nav.logout', $user['name']) . '</button></form>'
            . ' <button type="button" id="theme-toggle" title="' . t('nav.theme') . '">◐</button>';
    } else {
        $nav .= ' <button type="button" id="theme-toggle" title="' . t('nav.theme') . '">◐</button>';
    }
    $fm = flash();
    $fhtml = $fm !== null ? '<p class="flash">' . e($fm) . '</p>' : '';
    $js = '<script src="' . e(url('/assets/app.js')) . '" defer></script>';
    // Theme server-side too: already in HTML from cookie,
    // so no white flash in dark mode either.
    $themeAttr = (($_COOKIE['szk_theme'] ?? '') === 'dark') ? ' data-theme="dark"' : '';
    echo '<!DOCTYPE html><html lang="hu"' . $themeAttr . '><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . e($title) . ' — SzenzorKozpont</title><style>'
        . ':root{--green:#1d3a2f;--green-lt:#e7efe9;--accent:#2a9d4a;--amber:#b7791f;'
        . '--red:#c0392b;--ink:#232a26;--muted:#68736d;--card:#ffffff;--line:#dfe5e1}'
        . '[data-theme="dark"]{--ink:#e6ece8;--muted:#9aa5a0;--card:#1e2421;'
        . '--line:#2e3833;--green-lt:#22352c}'
        . '[data-theme="dark"] body{background:#141817}'
        . '[data-theme="dark"] tbody tr:nth-child(even){background:#1a201d}'
        . '[data-theme="dark"] tbody tr:hover{background:#222b27}'
        . '[data-theme="dark"] code{background:#222b27}'
        . '[data-theme="dark"] input,[data-theme="dark"] select{background:#1e2421;color:var(--ink)}'
        . '[data-theme="dark"] button.ghost{background:#1e2421;color:#7fd08a;border-color:#7fd08a}'
        . '[data-theme="dark"] .flash{background:#3a3220}'
        . '[data-theme="dark"] .warn{background:#3a2226}'
        . '[data-theme="dark"] .queued{background:#3a3220;border-color:var(--amber)}'
        . '[data-theme="dark"] .sen-stale{background:#3a3220;border-color:var(--amber)}'
        . 'body{font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;max-width:1080px;'
        . 'margin:0 auto;padding:1.2em 1em 3em;background:#eef2ef;color:var(--ink);line-height:1.45}'
        . 'nav{background:var(--green);color:#fff;padding:.7em 1em;border-radius:10px;margin-bottom:1.2em;'
        . 'position:sticky;top:.5em;z-index:10;box-shadow:0 2px 8px rgba(0,0,0,.15)}'
        . 'nav a{color:#cdeeda;margin-right:1em;text-decoration:none;font-weight:600}'
        . 'nav a:hover{text-decoration:underline}'
        . 'nav button{background:transparent;border:1px solid #cdeeda;color:#cdeeda;border-radius:6px;'
        . 'padding:.2em .7em;cursor:pointer}'
        . 'h1{font-size:1.5em;margin:.2em 0 .6em}h2{font-size:1.15em;margin:1.4em 0 .5em;'
        . 'padding-bottom:.3em;border-bottom:2px solid var(--green)}h3{font-size:1em;margin:1em 0 .4em}'
        . '.wrap{overflow-x:auto;background:var(--card);border:1px solid var(--line);border-radius:10px}'
        . 'table{border-collapse:collapse;width:100%;background:var(--card);margin:0;font-size:.9em}'
        . 'th,td{padding:.45em .6em;text-align:left;vertical-align:top;border-bottom:1px solid var(--line)}'
        . 'thead th{background:var(--green-lt);position:sticky;top:0;white-space:nowrap}'
        . 'tbody tr:nth-child(even){background:#f7faf8}tbody tr:hover{background:#eef5ef}'
        . '.flash{background:#fff4c2;border:1px solid var(--amber);padding:.6em .8em;border-radius:8px}'
        . '.dot{display:inline-block;width:.65em;height:.65em;border-radius:50%}.on{background:var(--accent)}'
        . '.off{background:#a9b3ad}'
        . '.card{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:.8em;margin:.6em 0}'
        . '.warn{background:#fdeceb;border:1px solid var(--red);padding:.6em .8em;border-radius:8px}'
        . 'input,select,button{font-size:.95em;padding:.3em .5em;margin:.15em 0;border:1px solid #c3ccc7;border-radius:6px}'
        . 'button{background:var(--green);color:#fff;border-color:var(--green);cursor:pointer;font-weight:600}'
        . 'button:hover{filter:brightness(1.12)}button.danger{background:var(--red);border-color:var(--red)}'
        . 'button.ghost{background:#fff;color:var(--green)}'
        . 'small{color:var(--muted)}code{background:#eef2ef;padding:.1em .35em;border-radius:4px}'
        . '.tabs{display:flex;gap:.4em;margin:.8em 0;flex-wrap:wrap}'
        . '.tabs a{padding:.45em 1em;border:1px solid var(--line);border-radius:999px;background:var(--card);'
        . 'text-decoration:none;color:var(--ink);font-weight:600}'
        . '.tabs a.act{background:var(--green);border-color:var(--green);color:#fff}'
        . '.pill{display:inline-block;min-width:1.6em;text-align:center;background:var(--green);color:#fff;'
        . 'border-radius:999px;font-size:.8em;padding:.05em .45em;margin-left:.35em}'
        . '.pill.warn{background:var(--amber)}.pill.bad{background:var(--red)}'
        . '.toolbar{display:flex;gap:.5em;align-items:center;flex-wrap:wrap;background:var(--card);'
        . 'border:1px solid var(--line);border-radius:10px;padding:.5em .7em;margin:.6em 0}'
        . '</style></head>'
        . '<body><nav><b>SzenzorKozpont</b> &nbsp;' . $nav . '</nav>'
        . '<h1>' . e($title) . '</h1>' . $fhtml . $body . $js . '</body></html>';
    exit;
}

/** History in SenML-light form (web chart + mobile API). */
function history_points(int $sensorId, int $rangeSec, int $max = 200): array
{
    $now = now_ts();
    $from = $now - $rangeSec;
    if ($rangeSec <= 48 * 3600) {
        $rows = qall('SELECT ts_client t, v_num, v_str, v_bool FROM readings_raw
                      WHERE sensor_id=? AND ts_server>=? ORDER BY ts_server DESC LIMIT ' . $max,
            [$sensorId, $from]);
        $rows = array_reverse($rows);
        $e = [];
        foreach ($rows as $r) {
            $p = ['n' => '', 't' => (int)$r['t']];
            if ($r['v_num'] !== null) {
                $p['v'] = (float)$r['v_num'];
            } elseif ($r['v_bool'] !== null) {
                $p['vb'] = ((int)$r['v_bool'] === 1);
            } else {
                $p['vs'] = (string)$r['v_str'];
            }
            $e[] = $p;
        }
        return ['bt' => 0, 'e' => $e];
    }
    $rows = qall('SELECT hour_ts t, hlast v FROM readings_hourly
                  WHERE sensor_id=? AND hour_ts>=? ORDER BY hour_ts DESC LIMIT ' . $max,
        [$sensorId, $from - ($from % 3600)]);
    $rows = array_reverse($rows);
    $e = [];
    foreach ($rows as $r) {
        $e[] = ['n' => '', 't' => (int)$r['t'], 'v' => (float)$r['v']];
    }
    return ['bt' => 0, 'e' => $e];
}

/** 24h sparkline (inline SVG) for numeric sensor. */
function sparkline(int $sensorId): string
{
    $h = history_points($sensorId, 24 * 3600, 100);
    $vals = [];
    foreach ($h['e'] as $p) {
        if (isset($p['v'])) {
            $vals[] = [(int)$p['t'], (float)$p['v']];
        }
    }
    if (count($vals) < 2) {
        return '';
    }
    $vs = array_column($vals, 1);
    $min = min($vs);
    $max = max($vs);
    $span = $max - $min > 0 ? $max - $min : 1;
    $t0 = $vals[0][0];
    $t1 = end($vals)[0];
    $tspan = $t1 - $t0 > 0 ? $t1 - $t0 : 1;
    $pts = [];
    foreach ($vals as [$t, $v]) {
        $x = 4 + 192 * ($t - $t0) / $tspan;
        $y = 30 - 26 * ($v - $min) / $span;
        $pts[] = sprintf('%.1f,%.1f', $x, $y);
    }
    return '<svg width="200" height="34" viewBox="0 0 200 34" role="img">'
        . '<polyline points="' . e(implode(' ', $pts)) . '" fill="none" stroke="#1d3a2f" stroke-width="1.5"/></svg>';
}

/** History chart (inline SVG, CSP-safe): series + min/mid/max grid.
 * $pts = [[ts, v], ...] in order, at least 2 points. */
function history_chart(array $pts, string $unit, int $decimals): string
{
    $n = count($pts);
    if ($n < 2) {
        return '';
    }
    $w = 640;
    $h = 220;
    $padL = 64;
    $padR = 10;
    $padT = 12;
    $padB = 30;
    $vs = array_column($pts, 1);
    $min = min($vs);
    $max = max($vs);
    if (!($max > $min)) {
        $max = $min + 1;
    }
    $t0 = (int)$pts[0][0];
    $t1 = (int)$pts[$n - 1][0];
    if (!($t1 > $t0)) {
        $t1 = $t0 + 1;
    }
    $fmtV = static function (float $v) use ($decimals, $unit): string {
        $s = number_format($v, $decimals, ',', ' ');
        return $unit !== '' ? $s . ' ' . $unit : $s;
    };
    $X = static function (int $t) use ($t0, $t1, $w, $padL, $padR): float {
        return $padL + ($w - $padL - $padR) * ($t - $t0) / ($t1 - $t0);
    };
    $Y = static function (float $v) use ($min, $max, $h, $padT, $padB): float {
        return $padT + ($h - $padT - $padB) * (1 - ($v - $min) / ($max - $min));
    };
    $pp = [];
    foreach ($pts as [$t, $v]) {
        $pp[] = sprintf('%.1f,%.1f', $X((int)$t), $Y((float)$v));
    }
    $svg = '<svg width="100%" viewBox="0 0 ' . $w . ' ' . $h . '" role="img"'
        . ' style="max-width:640px;background:#fff;border:1px solid var(--line);border-radius:10px">';
    foreach ([$min, ($min + $max) / 2, $max] as $gv) {
        $y = sprintf('%.1f', $Y((float)$gv));
        $svg .= '<line x1="' . $padL . '" y1="' . $y . '" x2="' . ($w - $padR)
            . '" y2="' . $y . '" stroke="#dfe5e1"/>'
            . '<text x="' . ($padL - 5) . '" y="' . sprintf('%.1f', (float)$y + 4) . '" font-size="11"'
            . ' text-anchor="end" fill="#68736d">' . e($fmtV((float)$gv)) . '</text>';
    }
    $svg .= '<polyline points="' . e(implode(' ', $pp)) . '" fill="none" stroke="#1d3a2f" stroke-width="1.5"/>';
    $svg .= '<text x="' . $padL . '" y="' . ($h - 8) . '" font-size="11" fill="#68736d">'
        . e(fmt_dt($t0)) . '</text>';
    $svg .= '<text x="' . ($w - $padR) . '" y="' . ($h - 8) . '" font-size="11" text-anchor="end"'
        . ' fill="#68736d">' . e(fmt_dt($t1)) . '</text>';
    return $svg . '</svg>';
}

/** Control form: plain ON/OFF, thermostat slider, lamp slider+color.
 * Values prefilled from sensor last report. */
function control_form(int $sensorId, string $back): string
{
    $s = q1('SELECT ctl_json, last_extra, last_v_num, last_v_bool FROM sensors WHERE id=?', [$sensorId]);
    $ctl = [];
    if ($s && !empty($s['ctl_json'])) {
        $d = json_decode((string)$s['ctl_json'], true);
        if (is_array($d) && in_array($d['type'] ?? '', ['light', 'climate'], true)) {
            $ctl = $d;
        }
    }
    $lx = [];
    if ($s && !empty($s['last_extra'])) {
        $d = json_decode((string)$s['last_extra'], true);
        if (is_array($d)) {
            $lx = $d;
        }
    }
    $num = static function ($v): ?float {
        return is_numeric($v) ? (float)$v : null;
    };
    $o = '<form method="post" action="' . e(url('/ui/api/control')) . '">'
        . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
        . '<input type="hidden" name="sensor_id" value="' . $sensorId . '">'
        . '<input type="hidden" name="back" value="' . e($back) . '">';
    if (($ctl['type'] ?? '') === 'climate') {
        // Thermostat: no ON/OFF or hidden state — decides from target temp,
        // reports current state back.
        // Target-temp slider only + mode and program rows (blank default).
        $tmin = $num($ctl['temp_min'] ?? null) ?? 5.0;
        $tmax = $num($ctl['temp_max'] ?? null) ?? 35.0;
        if (!($tmax > $tmin)) {
            $tmin = 5.0;
            $tmax = 35.0;
        }
        $step = ($num($ctl['temp_step'] ?? null) ?? 0.5);
        if (!($step > 0) || $step > 5) {
            $step = 0.5;
        }
        $cur = $num($lx['temperature'] ?? null)
            ?? $num($s['last_v_num'] ?? null) ?? $tmin;
        $cur = min($tmax, max($tmin, $cur));
        $o .= t('ctl.target') . ' <b><output id="tout-' . $sensorId . '">' . e((string)$cur) . '</output> °C</b>'
            . '<br><input type="range" name="temperature" min="' . e((string)$tmin) . '"'
            . ' max="' . e((string)$tmax) . '" step="' . e((string)$step) . '"'
            . ' value="' . e((string)$cur) . '" data-out="tout-' . $sensorId . '"> ';
        $modes = (isset($ctl['hvac_modes']) && is_array($ctl['hvac_modes'])) ? $ctl['hvac_modes'] : [];
        if ($modes) {
            $o .= '<br>' . t('ctl.mode') . ' <select name="hvac_mode"><option value="">—</option>';
            foreach ($modes as $m) {
                $o .= '<option value="' . e((string)$m) . '">' . e(hvac_label((string)$m)) . '</option>';
            }
            $o .= '</select>';
        }
        $pres = (isset($ctl['preset_modes']) && is_array($ctl['preset_modes'])) ? $ctl['preset_modes'] : [];
        if ($pres) {
            $o .= '<br>' . t('ctl.preset') . ' <select name="preset_mode"><option value="">—</option>';
            foreach ($pres as $pr) {
                $o .= '<option value="' . e((string)$pr) . '">' . e((string)$pr) . '</option>';
            }
            $o .= '</select>';
        }
    } else {
        // Default is OPPOSITE of current state: if ON now,
        // preselect OFF (and vice versa); ON if unknown.
        $isOn = $s && (int)($s['last_v_bool'] ?? 0) === 1;
        $o .= '<select name="state"><option value="on"' . ($isOn ? '' : ' selected') . '>' . t('ctl.on') . '</option>'
            . '<option value="off"' . ($isOn ? ' selected' : '') . '>' . t('ctl.off') . '</option>'
            . '<option value="toggle">' . t('ctl.toggle') . '</option></select> ';
        if (($ctl['type'] ?? '') === 'light') {
            if (!empty($ctl['brightness'])) {
                // Brightness slider 0–100%: last reported value,
                // else 50%.
                $bpct = 50;
                if (isset($lx['brightness']) && is_numeric($lx['brightness'])) {
                    $bpct = (int)round((float)$lx['brightness'] / 255 * 100);
                    $bpct = min(100, max(0, $bpct));
                }
                $o .= '<br>' . t('ctl.bright') . ' <b><output id="bout-' . $sensorId . '">' . $bpct
                    . '</output> %</b><br><input type="range" name="brightness_pct" min="0"'
                    . ' max="100" value="' . $bpct . '" data-out="bout-' . $sensorId . '"> ';
            }
            $modes = (array)($ctl['color_modes'] ?? []);
            if (array_intersect($modes, ['rgb', 'rgbw', 'rgbww'])) {
                // Graphic color picker with current RGB value (white if none).
                $rgb = [255, 255, 255];
                foreach (['rgbw_color', 'rgbww_color', 'rgb_color'] as $ck) {
                    $cv = $lx[$ck] ?? null;
                    if (is_array($cv) && count($cv) >= 3) {
                        $ok = true;
                        $iv = [];
                        foreach (array_slice($cv, 0, 3) as $cc) {
                            if (!is_numeric($cc) || $cc < 0 || $cc > 255) {
                                $ok = false;
                                break;
                            }
                            $iv[] = (int)$cc;
                        }
                        if ($ok) {
                            $rgb = $iv;
                            break;
                        }
                    }
                }
                $o .= '<br>' . t('ctl.color') . ' <input type="color" name="color" value="'
                    . sprintf('#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2]) . '"> ';
            }
        }
    }
    return $o . '<br><button type="submit">' . t('ctl.send') . '</button></form>';
}

/** Client actual current cycle time (s) + scope — mirrors client logic.
 * See protocol.poll_interval.
 * Day/night + set_poll override from last sysinfo.
 * expected_every_s NOT used: worst case (offline
 * detection/rate-limit base), not time-to-next-report. */
function client_interval_now(array $c): array
{
    try {
        $tz = new DateTimeZone(cfg()['display_tz'] ?? 'Europe/Budapest');
    } catch (Throwable $e) {
        $tz = new DateTimeZone('UTC');
    }
    $hm = (new DateTime('@' . now_ts()))->setTimezone($tz)->format('H:i');
    $norm = static function ($v, string $d): string {
        if (is_string($v) && preg_match('/^(\d{1,2}):(\d{2})$/', trim($v), $m)) {
            return sprintf('%02d:%02d', min(23, (int)$m[1]), min(59, (int)$m[2]));
        }
        return $d;
    };
    $from = $norm($c['day_from'] ?? null, '06:00');
    $to = $norm($c['day_to'] ?? null, '21:00');
    if ($from <= $to) {
        $day = ($hm >= $from && $hm < $to);
    } else {
        // Overnight window.
        $day = ($hm >= $from || $hm < $to);
    }
    $scope = $day ? 'day' : 'night';
    $iv = $day ? (int)$c['poll_day_s'] : (int)$c['poll_night_s'];
    if ($iv < 60 || $iv > 86400) {
        $iv = $day ? 600 : 1200;
    }
    // set_poll override (from client last sysinfo report).
    $si = q1('SELECT extra FROM sysinfo WHERE client_id=?', [(int)$c['id']]);
    if ($si) {
        $ex = json_decode((string)($si['extra'] ?? ''), true);
        $ov = [];
        if (is_array($ex)) {
            $p = $ex['poll'] ?? null;
            if (is_array($p) && is_array($p['override'] ?? null)) {
                $ov = $p['override'];
            }
        }
        if (isset($ov[$scope]) && (int)$ov[$scope] >= 60 && (int)$ov[$scope] <= 86400) {
            $iv = (int)$ov[$scope];
        }
    }
    return [$iv, $scope];
}

/** Queued control command display under control (ETA in minutes). */
function queued_control_info(array $s, array $c): string
{
    $q = q1("SELECT * FROM commands WHERE client_id=? AND type='control' AND target_n=?
             AND status IN ('queued','sent') ORDER BY id DESC LIMIT 1",
        [(int)$s['client_id'], $s['sensor_key']]);
    if (!$q) {
        return '';
    }
    $arg = json_decode((string)$q['payload'], true);
    if (!is_array($arg)) {
        $arg = [];
    }
    // Pickup expected next polling cycle: CURRENT
    // cycle time (day/night + override — see client_interval_now)
    // minus time since last report (rounded up, min. 1
    // min). E.g. 10-min day polling + report 3 min ago → ~7 min.
    [$iv] = client_interval_now($c);
    $remain = $iv;
    if ($s['last_seen'] !== null) {
        $remain = $iv - (now_ts() - (int)$s['last_seen']);
    }
    $mins = max(1, (int)ceil($remain / 60));
    return '<div class="queued">⏳ ' . t('queue.pending') . ' <b>' . e(control_arg_summary($arg)) . '</b>'
        . '<br><small>' . t('queue.eta', $mins) . '</small></div>';
}

/** Control-capability short label for admin table. */
function ctl_badge(?string $ctlJson): string
{
    if ($ctlJson === null || $ctlJson === '') {
        return '';
    }
    $d = json_decode($ctlJson, true);
    if (!is_array($d) || empty($d['type'])) {
        return '';
    }
    $t = $d['type'] === 'light' ? t('ctl.badge_light') : $d['type'];
    $extra = [];
    if (!empty($d['brightness'])) {
        $extra[] = t('ctl.badge_bright');
    }
    if (!empty($d['color_modes']) && is_array($d['color_modes'])) {
        $extra[] = implode(',', array_slice($d['color_modes'], 0, 4));
    }
    return '<br><small>[' . e($t . ($extra ? ': ' . implode(', ', $extra) : '')) . ']</small>';
}
