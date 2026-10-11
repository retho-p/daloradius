<?php
/*
 *********************************************************************************************************
 * daloRADIUS - RADIUS Web Platform
 * Copyright (C) 2007 - Liran Tal <liran@lirantal.com> All Rights Reserved.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
 *
 *********************************************************************************************************
 *
 * Authors:    Liran Tal <liran@lirantal.com>
 *             Filippo Lauria <filippo.lauria@iit.cnr.it>
 *
 *********************************************************************************************************
 */

include_once __DIR__ . '/../common/includes/config_read.php';
include $configValues['OPERATORS_LIBRARY'] . '/checklogin.php';
$operator = $_SESSION['operator_user'];
require_once __DIR__ . '/library/gis_read.php';

try {
    $gis_pdo = dalo_pdo_connect($configValues, $_SESSION['location_name'] ?? 'default');
    $gis_permissions = dalo_gis_permissions($gis_pdo, $configValues, $_SESSION['operator_id'] ?? null);
} catch (Throwable $error) {
    error_log('GIS permission lookup failed: ' . get_class($error));
    http_response_code(503);
    exit('Unable to check operator permissions.');
}
if (!$gis_permissions['view']) {
    header('Location: home-error.php');
    exit;
}
if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', array('GET', 'HEAD'), true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit;
}

include_once $configValues['OPERATORS_LANG'] . '/main.php';
include $configValues['COMMON_INCLUDES'] . '/layout.php';
$log = 'visited page: ';
$logAction = '';
$logDebugSQL = '';
$gis_items = array();
$gis_loaded = true;
try {
    $gis_items = dalo_gis_inventory($gis_pdo, $configValues);
} catch (Throwable $error) {
    error_log('GIS inventory lookup failed: ' . get_class($error));
    $gis_loaded = false;
    $failureMsg = t('gis', 'loadError');
    http_response_code(503);
}
$gis_pdo = null;
$gis_labels = array();
foreach (array('located', 'missing', 'invalid', 'empty', 'noResults', 'select', 'noPosition', 'invalidPosition', 'mapError', 'tileError', 'results', 'unnamed') as $key) {
    $gis_labels[$key] = t('gis', $key);
}
$gis_data = array('items' => $gis_items, 'permissions' => $gis_permissions, 'labels' => $gis_labels);
$gis_json = json_encode($gis_data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE);
$title = t('gis', 'title');
$extra_css = array('static/leaflet/leaflet.css?v=1.9.4', 'static/css/gis-map.css?v=1', 'static/css/gis-browser.css?v=1');
$extra_js = array('static/leaflet/leaflet.js?v=1.9.4', 'static/js/gis-map.js?v=1', 'static/js/gis-browser.js?v=1');
print_html_prologue($title, $langCode, $extra_css);
print_title_and_help($title, t('gis', 'help'));
include $configValues['OPERATORS_INCLUDE_MANAGEMENT'] . '/actionMessages.php';
?>
<section id="gis-browser" aria-label="<?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>">
    <div class="gis-toolbar d-flex flex-wrap gap-2 align-items-end mb-2">
        <div class="gis-search">
            <label for="gis-search" class="form-label small mb-1"><?= t('gis', 'search') ?></label>
            <input id="gis-search" type="search" class="form-control form-control-sm" autocomplete="off" disabled>
        </div>
        <div>
            <label for="gis-filter" class="form-label small mb-1"><?= t('gis', 'filter') ?></label>
            <select id="gis-filter" class="form-select form-select-sm" disabled>
                <option value="all"><?= t('gis', 'all') ?></option>
                <option value="located"><?= t('gis', 'located') ?></option>
                <option value="missing"><?= t('gis', 'missing') ?></option>
                <option value="invalid"><?= t('gis', 'invalid') ?></option>
            </select>
        </div>
        <button id="gis-fit" type="button" class="btn btn-outline-secondary btn-sm" disabled><?= t('gis', 'fit') ?></button>
        <span id="gis-count" class="small text-muted ms-lg-auto" role="status" aria-live="polite"></span>
    </div>
    <div id="gis-map-error" class="alert alert-warning small py-2" role="status" hidden></div>
    <div class="gis-browser-layout">
        <div class="gis-map-column">
            <div id="map" class="gis-map" role="region" aria-label="<?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>"></div>
        </div>
        <aside class="gis-inspector border rounded-1" aria-label="<?= t('gis', 'inventory') ?>">
            <div class="gis-inventory">
                <h4 class="fs-6 mb-2"><?= t('gis', 'inventory') ?></h4>
                <div id="gis-list" class="gis-hotspot-list" aria-label="<?= t('gis', 'inventory') ?>"></div>
                <p id="gis-empty" class="small text-muted mb-0" hidden></p>
                <nav class="gis-pagination d-flex align-items-center justify-content-between gap-2 mt-2" aria-label="<?= t('gis', 'pages') ?>">
                    <button id="gis-prev" type="button" class="btn btn-outline-secondary btn-sm" disabled><?= t('gis', 'previous') ?></button>
                    <span id="gis-page" class="small text-muted"></span>
                    <button id="gis-next" type="button" class="btn btn-outline-secondary btn-sm" disabled><?= t('gis', 'next') ?></button>
                </nav>
            </div>
            <div id="gis-detail" class="gis-detail border-top" aria-live="polite">
                <h4 class="fs-6"><?= t('gis', 'details') ?></h4>
                <p id="gis-selection-empty" class="small text-muted mb-0"><?= t('gis', 'select') ?></p>
                <div id="gis-selection" hidden>
                    <dl class="gis-detail-fields small mb-2">
                        <dt><?= t('gis', 'name') ?></dt><dd id="gis-name"></dd>
                        <dt><?= t('gis', 'mac') ?></dt><dd id="gis-mac"></dd>
                        <dt><?= t('gis', 'position') ?></dt><dd id="gis-position"></dd>
                    </dl>
                    <p id="gis-location-note" class="small text-muted" hidden></p>
                    <div class="d-flex flex-wrap gap-2">
                        <a id="gis-accounting" class="btn btn-outline-secondary btn-sm" hidden><?= t('gis', 'accounting') ?></a>
                        <a id="gis-compare" class="btn btn-outline-secondary btn-sm" href="acct-hotspot-compare.php" hidden><?= t('gis', 'compare') ?></a>
                    </div>
                </div>
            </div>
        </aside>
    </div>
    <?php if ($gis_permissions['edit']): ?>
    <p class="small text-muted mt-2 mb-0"><?= t('gis', 'readOnly') ?> <a href="gis-editmap.php"><?= t('gis', 'legacyEdit') ?></a></p>
    <?php endif; ?>
    <noscript><p class="alert alert-info mt-2"><?= t('gis', 'noJavascript') ?></p></noscript>
</section>
<?php if ($gis_loaded): ?>
<script id="gis-data" type="application/json"><?= $gis_json ?></script>
<?php endif; ?>
<?php
include $configValues['OPERATORS_INCLUDE_CONFIG'] . '/logging.php';
print_footer_and_html_epilogue('', $extra_js);
