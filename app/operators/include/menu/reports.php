<?php
/** Reports navigation: native disclosure groups around existing menu descriptors. */
if (strpos($_SERVER['PHP_SELF'], '/include/menu/reports.php') !== false) {
    http_response_code(404);
    exit;
}

require_once $configValues['COMMON_INCLUDES'] . '/pdo_connection.php';
require_once $configValues['OPERATORS_LIBRARY'] . '/operator_acl_read.php';

$reports_groups = array(
    array('default', t('submenu', 'General')),
    array('logs', t('submenu', 'Logs')),
    array('stat', t('submenu', 'Status')),
    array('batch', t('submenu', 'Batch Users')),
    array('hb', t('submenu', 'Dashboard')),
);
// Bind validation's reply options before loading descriptors inside a closure.
global $valid_radiusReplys;
require_once $configValues['COMMON_INCLUDES'] . '/validation.php';

// Isolate each descriptor's local variables; retain its existing page context.
$load_reports_sections = function ($category) use ($configValues) {
    global $options_format;
    include __DIR__ . '/sidebar/rep/' . $category . '.php';
    return $menu['sections'];
};

$reports_acl_pdo = null;
try {
    $reports_acl_pdo = dalo_pdo_connect($configValues, $_SESSION['location_name'] ?? 'default');
} catch (Throwable $error) {
    error_log('Reports navigation permission lookup failed: ' . get_class($error));
}
$reports_acl_cache = array();
$reports_route_allowed = function ($route) use ($configValues, $reports_acl_pdo, &$reports_acl_cache) {
    if ($reports_acl_pdo === null) {
        return false;
    }
    $permission = str_replace('-', '_', basename($route, '.php'));
    if (!array_key_exists($permission, $reports_acl_cache)) {
        try {
            $reports_acl_cache[$permission] = dalo_operator_acl_allowed(
                $reports_acl_pdo, $configValues, $_SESSION['operator_id'] ?? null, $permission
            );
        } catch (Throwable $error) {
            error_log('Reports navigation permission lookup failed: ' . get_class($error));
            $reports_acl_cache[$permission] = false;
        }
    }
    return $reports_acl_cache[$permission];
};

menu_open(t('sidebar', 'Reports'));
echo '<nav class="management-sidebar reports-sidebar" aria-label="Reports">';
foreach ($reports_groups as $group) {
    list($category, $label) = $group;
    try {
        $sections = $load_reports_sections($category);
    } catch (Throwable $error) {
        error_log('Reports navigation section failed: ' . get_class($error));
        continue;
    }
    $visible_sections = array();
    foreach ($sections as $section) {
        $visible_descriptors = array();
        foreach ($section['descriptors'] as $descriptor) {
            $route = $descriptor['href'] ?? $descriptor['action'] ?? '';
            if ($route !== '' && $reports_route_allowed($route)) {
                $visible_descriptors[] = $descriptor;
            }
        }
        if (!empty($visible_descriptors)) {
            $section['descriptors'] = $visible_descriptors;
            $visible_sections[] = $section;
        }
    }
    if (empty($visible_sections)) {
        continue;
    }
    $active = ($category === $detected_subcategory);
    $label = htmlspecialchars(strip_tags(trim($label)), ENT_QUOTES, 'UTF-8');
    printf('<details class="management-sidebar-group"%s data-active="%s">',
           $active ? ' open' : '', $active ? 'true' : 'false');
    echo '<summary><span>' . $label . '</span><i class="bi bi-chevron-down" aria-hidden="true"></i></summary>';
    foreach ($visible_sections as $section) {
        if (count($sections) > 1) {
            echo '<h3 class="management-sidebar-subheading">'
               . htmlspecialchars(strip_tags($section['title']), ENT_QUOTES, 'UTF-8') . '</h3>';
        }
        $class = 'nav nav-pills flex-column management-sidebar-menu ' . ($section['class'] ?? '');
        echo '<ul class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '">';
        foreach ($section['descriptors'] as $descriptor) {
            menu_print_item($descriptor);
        }
        echo '</ul>';
    }
    echo '</details>';
}
echo '</nav>';
menu_close();
unset($reports_route_allowed, $load_reports_sections, $reports_acl_pdo);
