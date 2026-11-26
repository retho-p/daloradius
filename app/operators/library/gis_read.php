<?php
/** GIS hotspot inventory, live session counts, and safe location updates. */
if (strpos($_SERVER['PHP_SELF'] ?? '', '/library/gis_read.php') !== false) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/geo_heartbeat_pdo.php';
require_once __DIR__ . '/operator_acl_read.php';

function dalo_gis_permissions(PDO $pdo, $config, $operator_id) {
    $view = dalo_operator_acl_allowed($pdo, $config, $operator_id, 'gis_viewmap');
    $edit = dalo_operator_acl_allowed($pdo, $config, $operator_id, 'gis_editmap');
    return array(
        // Operators with edit permission can also consult the unified map.
        'view' => $view || $edit,
        'edit' => $edit,
        'editHotspot' => dalo_operator_acl_allowed($pdo, $config, $operator_id, 'mng_hs_edit'),
        'online' => dalo_operator_acl_allowed($pdo, $config, $operator_id, 'rep_online'),
        'accounting' => dalo_operator_acl_allowed($pdo, $config, $operator_id, 'acct_hotspot_accounting'),
        'compare' => dalo_operator_acl_allowed($pdo, $config, $operator_id, 'acct_hotspot_compare'),
    );
}

function dalo_gis_format_coordinate($value) {
    $formatted = rtrim(rtrim(sprintf('%.6F', (float) $value), '0'), '.');
    return $formatted === '-0' ? '0' : $formatted;
}

function dalo_gis_normalize_geocode($value) {
    if (!is_string($value)) {
        throw new InvalidArgumentException('Invalid coordinates');
    }
    $trimmed = trim($value);
    if ($trimmed === '') {
        return '';
    }
    $coordinates = dalo_geo_coordinates($trimmed);
    if ($coordinates === false) {
        throw new InvalidArgumentException('Invalid coordinates');
    }
    return dalo_gis_format_coordinate($coordinates[0]) . ', ' . dalo_gis_format_coordinate($coordinates[1]);
}

function dalo_gis_active_sessions(PDO $pdo, $config) {
    $sessions = array();
    try {
        $radacct = dalo_hotspot_table($config, 'CONFIG_DB_TBL_RADACCT');
        $sql = "SELECT calledstationid, COUNT(*) AS active_sessions, COUNT(DISTINCT username) AS active_users, MAX(acctstarttime) AS last_session_start
                  FROM $radacct
                 WHERE (acctstoptime IS NULL OR acctstoptime = '0000-00-00 00:00:00')
                   AND calledstationid <> ''
                 GROUP BY calledstationid";
        $rows = dalo_hotspot_query($pdo, $sql)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $key = strtolower(trim((string) ($row['calledstationid'] ?? '')));
            if ($key === '') {
                continue;
            }
            $sessions[$key] = array(
                'sessions' => (int) ($row['active_sessions'] ?? 0),
                'users' => (int) ($row['active_users'] ?? 0),
                'lastStart' => trim((string) ($row['last_session_start'] ?? '')),
            );
        }
    } catch (Throwable $error) {
        error_log('GIS active sessions lookup failed: ' . get_class($error));
    }
    return $sessions;
}

function dalo_gis_heartbeats(PDO $pdo, $config) {
    $heartbeats = array();
    try {
        $node = dalo_heartbeat_table($config);
        $rows = dalo_hotspot_query($pdo, "SELECT mac, `time`, wan_ip, uptime FROM $node WHERE mac <> ''")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $key = strtolower(trim((string) ($row['mac'] ?? '')));
            if ($key === '') {
                continue;
            }
            $time = trim((string) ($row['time'] ?? ''));
            if ($time === '0000-00-00 00:00:00') {
                $time = '';
            }
            if (isset($heartbeats[$key]) && $heartbeats[$key]['time'] >= $time) {
                continue;
            }
            $heartbeats[$key] = array(
                'time' => $time,
                'wanIp' => trim((string) ($row['wan_ip'] ?? '')),
                'uptime' => trim((string) ($row['uptime'] ?? '')),
            );
        }
    } catch (Throwable $error) {
        error_log('GIS heartbeat lookup failed: ' . get_class($error));
    }
    return $heartbeats;
}

function dalo_gis_inventory(PDO $pdo, $config) {
    $table = dalo_hotspot_table($config);
    $rows = dalo_hotspot_query($pdo, "SELECT id, name, mac, geocode, address, company, type, owner FROM $table ORDER BY name, id")->fetchAll(PDO::FETCH_ASSOC);
    $sessions = dalo_gis_active_sessions($pdo, $config);
    $heartbeats = dalo_gis_heartbeats($pdo, $config);
    $items = array();
    foreach ($rows as $row) {
        $geocode = (string) ($row['geocode'] ?? '');
        $coordinates = dalo_geo_coordinates($geocode);
        $state = trim($geocode) === '' ? 'missing' : ($coordinates === false ? 'invalid' : 'located');
        $mac = (string) ($row['mac'] ?? '');
        $mac_key = strtolower(trim($mac));
        $activity = $mac_key !== '' && isset($sessions[$mac_key]) ? $sessions[$mac_key] : null;
        $heartbeat = $mac_key !== '' && isset($heartbeats[$mac_key]) ? $heartbeats[$mac_key] : null;
        if ($heartbeat !== null && $heartbeat['time'] === '' && $heartbeat['wanIp'] === '' && $heartbeat['uptime'] === '') {
            $heartbeat = null;
        }
        $items[] = array(
            // Never serialize BIGINT identifiers as JavaScript numbers.
            'id' => (string) $row['id'],
            'name' => (string) ($row['name'] ?? ''),
            'mac' => $mac,
            'geocode' => $geocode,
            'address' => trim((string) ($row['address'] ?? '')),
            'company' => trim((string) ($row['company'] ?? '')),
            'type' => trim((string) ($row['type'] ?? '')),
            'owner' => trim((string) ($row['owner'] ?? '')),
            'state' => $state,
            'coordinates' => $coordinates === false ? null : $coordinates,
            'activeSessions' => $activity ? $activity['sessions'] : 0,
            'activeUsers' => $activity ? $activity['users'] : 0,
            'lastSessionStart' => $activity ? $activity['lastStart'] : '',
            'heartbeat' => $heartbeat,
        );
    }
    return $items;
}

function dalo_gis_update_location(PDO $pdo, $config, $id, $geocode, $operator) {
    $id = dalo_geo_id($id);
    if (!is_string($operator) || $operator === '' || strpos($operator, "\0") !== false || !preg_match('//u', $operator)) {
        throw new InvalidArgumentException('Invalid audit identity');
    }
    $normalized = dalo_gis_normalize_geocode($geocode);
    return dalo_hotspot_mutate($pdo, $config, function ($pdo, $table) use ($id, $normalized, $operator) {
        dalo_hotspot_capacity($pdo, $table, array('geocode' => $normalized, 'updateby' => $operator));
        $row = dalo_hotspot_query($pdo, "SELECT id, name FROM $table WHERE id = ? FOR UPDATE", array($id))->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Hotspot not found');
        }
        $now = date('Y-m-d H:i:s');
        dalo_hotspot_query($pdo, "UPDATE $table SET geocode = ?, updatedate = ?, updateby = ? WHERE id = ?", array($normalized, $now, $operator, $id));
        return array(
            'id' => $id,
            'name' => (string) ($row['name'] ?? ''),
            'geocode' => $normalized,
        );
    });
}
