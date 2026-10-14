<?php
// SensorKozpont — permission evaluation:
// sensor_acl → group_acl → client_acl (sensor level wins).

declare(strict_types=1);

/** Admin sees all; clients assigned to user. */
function user_clients(array $u): array
{
    if (is_admin($u)) {
        return qall('SELECT * FROM clients ORDER BY name');
    }
    return qall('SELECT c.* FROM clients c WHERE EXISTS (
                     SELECT 1 FROM client_acl a WHERE a.user_id=? AND a.client_id=c.id)
                 OR EXISTS (SELECT 1 FROM group_acl g WHERE g.user_id=? AND g.client_id=c.id)
                 OR EXISTS (SELECT 1 FROM sensor_acl sa JOIN sensors se ON se.id=sa.sensor_id
                            WHERE sa.user_id=? AND se.client_id=c.id)
                 ORDER BY c.name', [$u['id'], $u['id'], $u['id']]);
}

/** Client "assigned" if user has any right to it
 *  (client_acl = base view, or group-/sensor-level row). */
function user_can_client(array $u, int $clientId): bool
{
    if (is_admin($u)) {
        return true;
    }
    if (q1('SELECT 1 x FROM client_acl WHERE user_id=? AND client_id=?', [$u['id'], $clientId])) {
        return true;
    }
    if (q1('SELECT 1 x FROM group_acl WHERE user_id=? AND client_id=?', [$u['id'], $clientId])) {
        return true;
    }
    return q1('SELECT 1 x FROM sensor_acl sa JOIN sensors se ON se.id=sa.sensor_id
               WHERE sa.user_id=? AND se.client_id=?', [$u['id'], $clientId]) !== null;
}

/**
 * Client sensors with user rights.
 * Only visible sensors included (can_view=1).
 */
function user_sensors(array $u, int $clientId): array
{
    $sensors = qall('SELECT * FROM sensors WHERE client_id=? ORDER BY
        CASE WHEN group_key IS NULL OR group_key=? THEN 1 ELSE 0 END,
        group_key, sort_no, label', [$clientId, '']);
    if (is_admin($u)) {
        foreach ($sensors as &$s) {
            $s['can_view'] = 1;
            $s['can_control'] = 1;
        }
        unset($s);
        return $sensors;
    }
    $out = [];
    foreach ($sensors as $s) {
        $r = resolve_right($u, $clientId, $s);
        if ($r['view']) {
            $s['can_view'] = 1;
            $s['can_control'] = $r['control'] ? 1 : 0;
            $out[] = $s;
        }
    }
    return $out;
}

/** One sensor right: sensor → group → client. can_control implies view. */
function resolve_right(array $u, int $clientId, array $sensor): array
{
    // Admin has full rights everywhere (same as user_sensors); without it
    // mobile/end-user control would give "forbidden" for admins too.
    if (is_admin($u)) {
        return ['view' => true, 'control' => true];
    }
    // Sensor explicitly denied (collect=no) or unapproved (pending)
    // is NEVER visible to users — not even with group rights
    // (group rights cover collect=yes sensors only).
    if (($sensor['collect'] ?? 'yes') !== 'yes') {
        return ['view' => false, 'control' => false];
    }
    $sa = q1('SELECT * FROM sensor_acl WHERE user_id=? AND sensor_id=?',
        [$u['id'], $sensor['id']]);
    if ($sa) {
        return ['view' => (int)$sa['can_view'] === 1 || (int)$sa['can_control'] === 1,
                'control' => (int)$sa['can_control'] === 1];
    }
    if (!empty($sensor['group_key'])) {
        $ga = q1('SELECT * FROM group_acl WHERE user_id=? AND client_id=? AND group_key=?',
            [$u['id'], $clientId, $sensor['group_key']]);
        if ($ga) {
            return ['view' => (int)$ga['can_view'] === 1 || (int)$ga['can_control'] === 1,
                    'control' => (int)$ga['can_control'] === 1];
        }
    }
    $ca = q1('SELECT 1 x FROM client_acl WHERE user_id=? AND client_id=?',
        [$u['id'], $clientId]);
    if ($ca) {
        return ['view' => true, 'control' => false];
    }
    return ['view' => false, 'control' => false];
}

/** Connectivity: online if last_seen fresh vs expected_every_s×3. */
function conn_status(?int $lastSeen, ?int $expected, int $now): array
{
    if ($lastSeen === null || $expected === null || $expected <= 0) {
        return ['online' => false, 'ago' => t('dash.ago_none'), 'label' => t('dash.none')];
    }
    $online = ($now - $lastSeen) <= $expected * 3;
    return ['online' => $online, 'ago' => ago_hu($lastSeen, $now),
            'label' => $online ? t('dash.online') : t('dash.nodata')];
}

/** Sensor display value (calibrated + rounded). */
function display_value(array $s): string
{
    if ($s['last_ts_client'] === null) {
        return '—';
    }
    if ($s['last_v_num'] !== null) {
        $v = calibrated((float)$s['last_v_num'], (float)$s['slope'], (float)$s['offset_']);
        $d = max(0, min(6, (int)$s['decimals']));
        $out = number_format($v, $d, ',', ' ');
        $out .= ($s['unit'] !== '' ? ' ' . $s['unit'] : '');
        return $out . format_extra($s['last_extra'] ?? null, (string)$s['unit']);
    }
    if ($s['last_v_bool'] !== null) {
        $txt = ((int)$s['last_v_bool'] === 1 ? 'BE' : 'KI');
        return $txt . format_extra($s['last_extra'] ?? null, (string)$s['unit']);
    }
    return (string)$s['last_v_str'];
}
