<?php
/** Management navigation: native disclosure groups around existing menu descriptors. */
if (strpos($_SERVER['PHP_SELF'], '/include/menu/management.php') !== false) {
    http_response_code(404);
    exit;
}

require_once $configValues['COMMON_INCLUDES'] . '/pdo_connection.php';
require_once $configValues['OPERATORS_LIBRARY'] . '/operator_acl_read.php';

$management_groups = array(
    array('default', t('sidebar', 'UsersManagement')),
    array('batch', t('submenu', 'Batch Users')),
    array('hs', t('submenu', 'Hotspots')),
    array('rad-nas', t('submenu', 'Nas')),
    array('rad-usergroup', t('submenu', 'User-Groups')),
    array('rad-profiles', t('submenu', 'Profiles')),
    array('rad-hunt', t('submenu', 'HuntGroups')),
    array('rad-attributes', t('submenu', 'Attributes')),
    array('rad-realms', t('submenu', 'Realm/Proxy')),
    array('rad-ippool', t('submenu', 'IP-Pool')),
);
$active_management_group = ($detected_subcategory === 'rad-groups')
                         ? 'rad-profiles' : $detected_subcategory;

// Isolate each descriptor's local variables; retain its existing page context.
$load_management_sections = function ($category) use ($configValues) {
    global $options_format;
    include __DIR__ . '/sidebar/mng/' . $category . '.php';
    return $menu['sections'];
};

$management_acl_pdo = null;
try {
    $management_acl_pdo = dalo_pdo_connect($configValues, $_SESSION['location_name'] ?? 'default');
} catch (Throwable $error) {
    error_log('Management navigation permission lookup failed: ' . get_class($error));
}
$management_acl_cache = array();
$management_route_allowed = function ($route) use ($configValues, $management_acl_pdo, &$management_acl_cache) {
    if ($management_acl_pdo === null) {
        return false;
    }
    $permission = str_replace('-', '_', basename($route, '.php'));
    if (!array_key_exists($permission, $management_acl_cache)) {
        try {
            $management_acl_cache[$permission] = dalo_operator_acl_allowed(
                $management_acl_pdo, $configValues, $_SESSION['operator_id'] ?? null, $permission
            );
        } catch (Throwable $error) {
            error_log('Management navigation permission lookup failed: ' . get_class($error));
            $management_acl_cache[$permission] = false;
        }
    }
    return $management_acl_cache[$permission];
};

menu_open(t('sidebar', 'Management'));
echo '<nav class="management-sidebar" aria-label="Management">';
foreach ($management_groups as $group) {
    list($category, $label) = $group;
    // Preserve legacy group-check/reply screens within the Profiles category.
    $source_category = ($category === 'rad-profiles' && $detected_subcategory === 'rad-groups')
                     ? 'rad-groups' : $category;
    try {
        $sections = $load_management_sections($source_category);
    } catch (Throwable $error) {
        error_log('Management navigation section failed: ' . get_class($error));
        continue;
    }
    $visible_sections = array();
    foreach ($sections as $section) {
        $visible_descriptors = array();
        foreach ($section['descriptors'] as $descriptor) {
            $route = $descriptor['href'] ?? $descriptor['action'] ?? '';
            if ($route !== '' && $management_route_allowed($route)) {
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
    $active = ($category === $active_management_group);
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
unset($management_route_allowed, $load_management_sections, $management_acl_pdo);
