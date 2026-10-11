<?php
/** Read-only GIS inventory and existing operator permissions. */
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
        // The legacy editing screen already displays this same inventory.
        'view' => $view || $edit,
        'edit' => $edit,
        'accounting' => dalo_operator_acl_allowed($pdo, $config, $operator_id, 'acct_hotspot_accounting'),
        'compare' => dalo_operator_acl_allowed($pdo, $config, $operator_id, 'acct_hotspot_compare'),
    );
}

function dalo_gis_inventory(PDO $pdo, $config) {
    $table = dalo_hotspot_table($config);
    $rows = dalo_hotspot_query($pdo, "SELECT id, name, mac, geocode FROM $table ORDER BY name, id")->fetchAll(PDO::FETCH_ASSOC);
    $items = array();
    foreach ($rows as $row) {
        $geocode = (string) ($row['geocode'] ?? '');
        $coordinates = dalo_geo_coordinates($geocode);
        $state = trim($geocode) === '' ? 'missing' : ($coordinates === false ? 'invalid' : 'located');
        $items[] = array(
            // Never serialize BIGINT identifiers as JavaScript numbers.
            'id' => (string) $row['id'],
            'name' => (string) ($row['name'] ?? ''),
            'mac' => (string) ($row['mac'] ?? ''),
            'geocode' => $geocode,
            'state' => $state,
            'coordinates' => $coordinates === false ? null : $coordinates,
        );
    }
    return $items;
}
