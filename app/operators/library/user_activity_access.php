<?php
/** Navigation and page access reuse the four existing report permissions. */
if (strpos($_SERVER['PHP_SELF'] ?? '', '/library/user_activity_access.php') !== false) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/user_activity_read.php';
require_once __DIR__ . '/operator_acl_read.php';

function dalo_activity_allowed_views(PDO $pdo, array $config, $operatorId) {
    $allowed = array();
    foreach (dalo_activity_views() as $key => $view) {
        if (dalo_operator_acl_allowed($pdo, $config, $operatorId, $view['permission'])) {
            $allowed[$key] = $view;
        }
    }
    return $allowed;
}
