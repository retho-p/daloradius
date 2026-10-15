<?php
/** Section navigation: native disclosure groups around existing menu descriptors. */
if (strpos($_SERVER['PHP_SELF'], '/include/menu/section-navigation.php') !== false) {
    http_response_code(404);
    exit;
}

require_once $configValues['COMMON_INCLUDES'] . '/pdo_connection.php';
require_once $configValues['OPERATORS_LIBRARY'] . '/operator_acl_read.php';

$section_definitions = array(
    'acct' => array('Accounting', array(
        array('default', 'General'), array('plans', 'Plans'), array('custom', 'Custom'),
        array('hotspot', 'Hotspot'), array('maintenance', 'Maintenance'),
    )),
    'bill' => array('Billing', array(
        array('default', 'POS'), array('plans', 'Plans'), array('rates', 'Rates'),
        array('merchant', 'Merchant-Transactions'), array('history', 'Billing-History'),
        array('invoice', 'Invoices'), array('payments', 'Payments'),
    )),
    'config' => array('Configuration', array(
        array('default', 'General'), array('reports', 'Reporting'), array('maint', 'Maintenance'),
        array('operators', 'Operators'), array('backup', 'Backup'), array('mail', 'Mail'),
    )),
);
if (!isset($section_definitions[$detected_category])) {
    return;
}
list($section_title_key, $section_groups) = $section_definitions[$detected_category];
$section_title = t('sidebar', $section_title_key);
// Validation options must remain visible to descriptor globals when first loaded here.
global $acct_custom_query_options_all, $acct_custom_query_options_default,
       $bill_history_query_options_all, $bill_history_query_options_default,
       $bill_merchant_transactions_options_all, $bill_merchant_transactions_options_default,
       $valid_billactions, $valid_paymentStatus, $valid_vendorTypes;
require_once $configValues['COMMON_INCLUDES'] . '/validation.php';

// Isolate each descriptor's local variables; retain its existing page context.
$load_section_descriptors = function ($category) use ($configValues, $detected_category) {
    global $options_format;
    include __DIR__ . '/sidebar/' . $detected_category . '/' . $category . '.php';
    return $menu['sections'];
};

$section_acl_pdo = null;
try {
    $section_acl_pdo = dalo_pdo_connect($configValues, $_SESSION['location_name'] ?? 'default');
} catch (Throwable $error) {
    error_log('Section navigation permission lookup failed: ' . get_class($error));
}
$section_acl_cache = array();
$section_route_allowed = function ($route) use ($configValues, $section_acl_pdo, &$section_acl_cache) {
    if ($section_acl_pdo === null) {
        return false;
    }
    $permission = str_replace('-', '_', basename($route, '.php'));
    if (!array_key_exists($permission, $section_acl_cache)) {
        try {
            $section_acl_cache[$permission] = dalo_operator_acl_allowed(
                $section_acl_pdo, $configValues, $_SESSION['operator_id'] ?? null, $permission
            );
        } catch (Throwable $error) {
            error_log('Section navigation permission lookup failed: ' . get_class($error));
            $section_acl_cache[$permission] = false;
        }
    }
    return $section_acl_cache[$permission];
};

menu_open($section_title);
echo '<nav class="management-sidebar section-sidebar" aria-label="'
   . htmlspecialchars(strip_tags($section_title), ENT_QUOTES, 'UTF-8') . '">';
foreach ($section_groups as $group) {
    list($category, $label) = $group;
    try {
        $sections = $load_section_descriptors($category);
    } catch (Throwable $error) {
        error_log('Section navigation section failed: ' . get_class($error));
        continue;
    }
    $visible_sections = array();
    foreach ($sections as $section) {
        $visible_descriptors = array();
        foreach ($section['descriptors'] as $descriptor) {
            $route = $descriptor['href'] ?? $descriptor['action'] ?? '';
            if ($route !== '' && $section_route_allowed($route)) {
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
    $label = htmlspecialchars(strip_tags(trim(t('submenu', $label))), ENT_QUOTES, 'UTF-8');
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
unset($section_route_allowed, $load_section_descriptors, $section_acl_pdo);
