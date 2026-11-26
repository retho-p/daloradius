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

$request_method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($request_method, array('GET', 'HEAD', 'POST'), true)) {
    header('Allow: GET, HEAD, POST');
    http_response_code(405);
    exit;
}

include_once $configValues['OPERATORS_LANG'] . '/main.php';
include $configValues['COMMON_INCLUDES'] . '/layout.php';
$log = 'visited page: ';
$logAction = '';
$logDebugSQL = '';

$gis_escape = function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

if ($request_method === 'POST') {
    if (!$gis_permissions['edit']) {
        header('Location: home-error.php');
        exit;
    }

    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $hotspot_id = is_string($_POST['hotspot_id'] ?? null) ? trim($_POST['hotspot_id']) : '';
    $redirect_target = 'gis-main.php';
    if (preg_match('/\A[1-9][0-9]{0,18}\z/', $hotspot_id)) {
        $redirect_target .= '?hotspot=' . rawurlencode($hotspot_id);
    }

    if (!isset($_POST['csrf_token']) || !is_string($_POST['csrf_token']) || !dalo_check_csrf_token($_POST['csrf_token'])) {
        $_SESSION['gis_flash'] = array(
            'type' => 'danger',
            'message' => $gis_escape(t('gis', 'csrfError')),
        );
    } elseif (!in_array($action, array('set_location', 'clear_location'), true)) {
        $_SESSION['gis_flash'] = array(
            'type' => 'danger',
            'message' => $gis_escape(t('gis', 'locationError')),
        );
    } else {
        try {
            $geocode_input = is_string($_POST['geocode'] ?? null) ? trim($_POST['geocode']) : '';
            if ($action === 'set_location' && $geocode_input === '') {
                throw new InvalidArgumentException('Missing coordinates');
            }
            $updated = dalo_gis_update_location(
                $gis_pdo,
                $configValues,
                $hotspot_id,
                $action === 'clear_location' ? '' : $geocode_input,
                $operator
            );
            $display_name = $updated['name'] !== '' ? $updated['name'] : t('gis', 'unnamed') . ' #' . $updated['id'];
            $template = $action === 'clear_location' ? t('gis', 'locationCleared') : t('gis', 'locationSaved');
            $_SESSION['gis_flash'] = array(
                'type' => 'success',
                'message' => str_replace('{name}', '<strong>' . $gis_escape($display_name) . '</strong>', $gis_escape($template)),
            );
            $logAction = ($action === 'clear_location' ? 'Cleared' : 'Updated') . ' GIS location for hotspot ' . $display_name . ' on page: ';
        } catch (InvalidArgumentException $error) {
            $_SESSION['gis_flash'] = array(
                'type' => 'danger',
                'message' => $gis_escape(t('gis', 'invalidInput')),
            );
        } catch (Throwable $error) {
            error_log('GIS location update failed: ' . get_class($error));
            $_SESSION['gis_flash'] = array(
                'type' => 'danger',
                'message' => $gis_escape(t('gis', 'locationError')),
            );
        }
    }

    $gis_pdo = null;
    include $configValues['OPERATORS_INCLUDE_CONFIG'] . '/logging.php';
    header('Location: ' . $redirect_target, true, 303);
    exit;
}

if (isset($_SESSION['gis_flash']) && is_array($_SESSION['gis_flash'])) {
    $gis_flash = $_SESSION['gis_flash'];
    unset($_SESSION['gis_flash']);
    if (($gis_flash['type'] ?? '') === 'success' && !empty($gis_flash['message'])) {
        $successMsg = (string) $gis_flash['message'];
    } elseif (!empty($gis_flash['message'])) {
        $failureMsg = (string) $gis_flash['message'];
    }
}

$gis_items = array();
$gis_loaded = true;
try {
    $gis_items = dalo_gis_inventory($gis_pdo, $configValues);
} catch (Throwable $error) {
    error_log('GIS inventory lookup failed: ' . get_class($error));
    $gis_loaded = false;
    $failureMsg = $gis_escape(t('gis', 'loadError'));
    http_response_code(503);
}
$gis_pdo = null;

$gis_csrf_token = $gis_permissions['edit'] ? dalo_csrf_token() : '';
$gis_labels = array();
foreach (array(
    'located', 'missing', 'invalid', 'empty', 'noResults', 'select', 'noPosition',
    'invalidPosition', 'mapError', 'tileError', 'results', 'unnamed', 'onlineSummary',
    'onlineBadge', 'copy', 'copied', 'setLocation', 'moveLocation', 'editBanner',
    'invalidInput', 'confirmClear',
) as $key) {
    $gis_labels[$key] = t('gis', $key);
}
$gis_data = array('items' => $gis_items, 'permissions' => $gis_permissions, 'labels' => $gis_labels);
$gis_json = json_encode($gis_data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE);
$title = t('gis', 'title');
$extra_css = array('static/leaflet/leaflet.css?v=1.9.4', 'static/css/gis-map.css?v=1', 'static/css/gis-browser.css?v=5');
$extra_js = array('static/leaflet/leaflet.js?v=1.9.4', 'static/js/gis-map.js?v=1', 'static/js/gis-browser.js?v=5');
print_html_prologue($title, $langCode, $extra_css);
?>
<?php if (!empty($failureMsg)): ?>
<?php
$gis_toast_success = $successMsg ?? '';
unset($successMsg);
include $configValues['OPERATORS_INCLUDE_MANAGEMENT'] . '/actionMessages.php';
if ($gis_toast_success !== '') {
    $successMsg = $gis_toast_success;
}
?>
<?php endif; ?>
<section id="gis-browser" aria-label="<?= $gis_escape($title) ?>">
    <div class="card shadow-sm mb-2">
        <div class="card-body py-2 px-3">
            <div class="gis-toolbar d-flex flex-wrap gap-2 align-items-end">
                <div class="gis-search">
                    <label for="gis-search" class="form-label small text-muted mb-1"><?= $gis_escape(t('gis', 'search')) ?></label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text"><i class="bi bi-search" aria-hidden="true"></i></span>
                        <input id="gis-search" type="search" class="form-control" autocomplete="off" disabled>
                    </div>
                </div>
                <div class="gis-filter-group">
                    <span id="gis-filter-label" class="form-label small text-muted mb-1 d-block"><?= $gis_escape(t('gis', 'filter')) ?></span>
                    <div id="gis-filters" class="gis-filters d-flex flex-wrap gap-1" role="group" aria-labelledby="gis-filter-label">
                        <button type="button" class="btn btn-outline-secondary btn-sm gis-filter-btn" data-filter="all" aria-pressed="true" disabled>
                            <?= $gis_escape(t('gis', 'all')) ?> <span class="gis-filter-count" data-count-for="all"></span>
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm gis-filter-btn gis-filter-online" data-filter="online" aria-pressed="false" disabled>
                            <i class="bi bi-broadcast" aria-hidden="true"></i><?= $gis_escape(t('gis', 'online')) ?> <span class="gis-filter-count" data-count-for="online"></span>
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm gis-filter-btn gis-filter-located" data-filter="located" aria-pressed="false" disabled>
                            <?= $gis_escape(t('gis', 'located')) ?> <span class="gis-filter-count" data-count-for="located"></span>
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm gis-filter-btn" data-filter="missing" aria-pressed="false" disabled>
                            <?= $gis_escape(t('gis', 'missing')) ?> <span class="gis-filter-count" data-count-for="missing"></span>
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm gis-filter-btn gis-filter-invalid" data-filter="invalid" aria-pressed="false" disabled>
                            <?= $gis_escape(t('gis', 'invalid')) ?> <span class="gis-filter-count" data-count-for="invalid"></span>
                        </button>
                    </div>
                </div>
                <div>
                    <label for="gis-sort" class="form-label small text-muted mb-1"><?= $gis_escape(t('gis', 'sort')) ?></label>
                    <select id="gis-sort" class="form-select form-select-sm" disabled>
                        <option value="name"><?= $gis_escape(t('gis', 'sortName')) ?></option>
                        <option value="online"><?= $gis_escape(t('gis', 'sortOnline')) ?></option>
                        <option value="state"><?= $gis_escape(t('gis', 'sortState')) ?></option>
                    </select>
                </div>
                <button id="gis-fit" type="button" class="btn btn-outline-secondary btn-sm" disabled>
                    <i class="bi bi-arrows-fullscreen me-1" aria-hidden="true"></i><?= $gis_escape(t('gis', 'fit')) ?>
                </button>
                <div class="gis-toolbar-actions d-flex align-items-center gap-2 ms-xl-auto">
                    <span id="gis-count" class="small text-muted" role="status" aria-live="polite"></span>
                    <div class="d-inline-flex align-items-center gap-1">
                        <a id="gis-refresh" href="gis-main.php" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-arrow-clockwise me-1" aria-hidden="true"></i><?= $gis_escape(t('gis', 'refresh')) ?>
                        </a>
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#gis-help-modal" aria-label="<?= $gis_escape($title . ' :: help') ?>" title="<?= $gis_escape($title . ' :: help') ?>">
                            <i class="bi bi-question-circle" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div id="gis-map-error" class="alert alert-warning small py-2 mb-2" role="status" hidden></div>
    <div id="gis-edit-banner" class="alert alert-primary small py-2 px-3 mb-2 gis-edit-banner" role="status" hidden>
        <span><i class="bi bi-geo-alt-fill me-1" aria-hidden="true"></i><span id="gis-edit-banner-text"></span></span>
        <button id="gis-edit-banner-cancel" type="button" class="btn btn-outline-primary btn-sm py-0"><?= $gis_escape(t('gis', 'cancel')) ?></button>
    </div>
    <div class="gis-browser-layout">
        <div class="gis-map-column">
            <div class="card shadow-sm">
                <div class="card-body p-0 overflow-hidden rounded-1">
                    <div id="map" class="gis-map border-0" role="region" aria-label="<?= $gis_escape($title) ?>"></div>
                </div>
            </div>
        </div>
        <aside class="gis-inspector card shadow-sm" aria-label="<?= $gis_escape(t('gis', 'inventory')) ?>">
            <div class="card-header bg-light py-2 px-3 d-flex align-items-center justify-content-between gap-2">
                <span class="fw-semibold small d-flex align-items-center gap-1">
                    <i class="bi bi-router text-primary" aria-hidden="true"></i><?= $gis_escape(t('gis', 'inventory')) ?>
                </span>
                <span id="gis-page" class="small text-muted"></span>
            </div>
            <div class="gis-inventory">
                <div id="gis-list" class="gis-hotspot-list" aria-label="<?= $gis_escape(t('gis', 'inventory')) ?>"></div>
                <p id="gis-empty" class="small text-muted mb-0 p-2" hidden></p>
                <nav class="gis-pagination d-flex align-items-center justify-content-between gap-2 pt-2 border-top" aria-label="<?= $gis_escape(t('gis', 'pages')) ?>">
                    <button id="gis-prev" type="button" class="btn btn-outline-secondary btn-sm" disabled><?= $gis_escape(t('gis', 'previous')) ?></button>
                    <button id="gis-next" type="button" class="btn btn-outline-secondary btn-sm" disabled><?= $gis_escape(t('gis', 'next')) ?></button>
                </nav>
            </div>
            <div id="gis-detail" class="gis-detail border-top" aria-live="polite">
                <div class="gis-detail-header bg-light border-bottom py-2 px-3 d-flex align-items-center justify-content-between gap-2">
                    <span class="fw-semibold small d-flex align-items-center gap-1">
                        <i class="bi bi-info-circle text-primary" aria-hidden="true"></i><?= $gis_escape(t('gis', 'details')) ?>
                    </span>
                    <button id="gis-clear-selection" type="button" class="btn-close gis-clear-btn" aria-label="<?= $gis_escape(t('gis', 'clearSelection')) ?>" hidden></button>
                </div>
                <div class="gis-detail-body p-3">
                    <p id="gis-selection-empty" class="small text-muted mb-0"><?= $gis_escape(t('gis', 'select')) ?></p>
                    <div id="gis-selection" hidden>
                        <div class="d-flex flex-wrap align-items-center gap-1 mb-2">
                            <span id="gis-state-badge" class="badge"></span>
                            <span id="gis-online-badge" class="badge text-bg-success" hidden></span>
                        </div>
                        <table class="table table-sm mb-2 gis-kv-table">
                            <tbody>
                                <tr>
                                    <th scope="row"><?= $gis_escape(t('gis', 'name')) ?></th>
                                    <td id="gis-name" class="fw-semibold"></td>
                                </tr>
                                <tr>
                                    <th scope="row"><?= $gis_escape(t('gis', 'mac')) ?></th>
                                    <td>
                                        <span class="gis-copy-cell">
                                            <span id="gis-mac" class="font-monospace small"></span>
                                            <button id="gis-copy-mac" type="button" class="btn btn-link btn-sm p-0 gis-copy-btn" aria-label="<?= $gis_escape(t('gis', 'copy')) ?>" title="<?= $gis_escape(t('gis', 'copy')) ?>" hidden>
                                                <i class="bi bi-clipboard" aria-hidden="true"></i>
                                            </button>
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?= $gis_escape(t('gis', 'position')) ?></th>
                                    <td>
                                        <span class="gis-copy-cell">
                                            <span id="gis-position" class="font-monospace small"></span>
                                            <button id="gis-copy-position" type="button" class="btn btn-link btn-sm p-0 gis-copy-btn" aria-label="<?= $gis_escape(t('gis', 'copy')) ?>" title="<?= $gis_escape(t('gis', 'copy')) ?>" hidden>
                                                <i class="bi bi-clipboard" aria-hidden="true"></i>
                                            </button>
                                        </span>
                                    </td>
                                </tr>
                                <tr id="gis-activity-row" hidden>
                                    <th scope="row"><?= $gis_escape(t('gis', 'activity')) ?></th>
                                    <td id="gis-activity"></td>
                                </tr>
                                <tr id="gis-heartbeat-row" hidden>
                                    <th scope="row"><?= $gis_escape(t('gis', 'heartbeat')) ?></th>
                                    <td id="gis-heartbeat" class="small"></td>
                                </tr>
                                <tr id="gis-type-row" hidden>
                                    <th scope="row"><?= $gis_escape(t('gis', 'type')) ?></th>
                                    <td id="gis-type"></td>
                                </tr>
                                <tr id="gis-company-row" hidden>
                                    <th scope="row"><?= $gis_escape(t('gis', 'company')) ?></th>
                                    <td id="gis-company"></td>
                                </tr>
                                <tr id="gis-address-row" hidden>
                                    <th scope="row"><?= $gis_escape(t('gis', 'address')) ?></th>
                                    <td id="gis-address"></td>
                                </tr>
                                <tr id="gis-owner-row" hidden>
                                    <th scope="row"><?= $gis_escape(t('gis', 'owner')) ?></th>
                                    <td id="gis-owner"></td>
                                </tr>
                            </tbody>
                        </table>
                        <p id="gis-location-note" class="small text-muted mb-2" hidden></p>
                        <?php if ($gis_permissions['edit']): ?>
                        <form id="gis-location-editor" method="POST" action="gis-main.php" class="border rounded-1 bg-light p-2 mb-2" hidden>
                            <input type="hidden" name="csrf_token" value="<?= $gis_escape($gis_csrf_token) ?>">
                            <input type="hidden" id="gis-form-action" name="action" value="set_location">
                            <input type="hidden" id="gis-form-hotspot-id" name="hotspot_id" value="">
                            <label for="gis-geocode-input" class="form-label small fw-semibold mb-1"><?= $gis_escape(t('gis', 'position')) ?></label>
                            <input id="gis-geocode-input" name="geocode" type="text" class="form-control form-control-sm font-monospace mb-1" placeholder="<?= $gis_escape(t('gis', 'geocodePlaceholder')) ?>" autocomplete="off">
                            <div id="gis-geocode-error" class="small text-danger mb-1" role="alert" hidden></div>
                            <div class="d-flex flex-wrap gap-1 pt-1">
                                <button id="gis-save-location" type="submit" class="btn btn-primary btn-sm">
                                    <i class="bi bi-check-lg me-1" aria-hidden="true"></i><?= $gis_escape(t('gis', 'saveLocation')) ?>
                                </button>
                                <button id="gis-cancel-location" type="button" class="btn btn-outline-secondary btn-sm"><?= $gis_escape(t('gis', 'cancel')) ?></button>
                                <button id="gis-clear-location" type="button" class="btn btn-outline-danger btn-sm ms-auto" hidden>
                                    <i class="bi bi-trash me-1" aria-hidden="true"></i><?= $gis_escape(t('gis', 'clearLocation')) ?>
                                </button>
                            </div>
                        </form>
                        <?php endif; ?>
                        <div class="d-flex flex-wrap gap-1">
                            <?php if ($gis_permissions['edit']): ?>
                            <button id="gis-edit-location-btn" type="button" class="btn btn-outline-primary btn-sm">
                                <i class="bi bi-geo-alt me-1" aria-hidden="true"></i><span id="gis-edit-location-label"><?= $gis_escape(t('gis', 'setLocation')) ?></span>
                            </button>
                            <?php endif; ?>
                            <a id="gis-edit-hotspot" class="btn btn-outline-secondary btn-sm" hidden>
                                <i class="bi bi-pencil me-1" aria-hidden="true"></i><?= $gis_escape(t('gis', 'editHotspot')) ?>
                            </a>
                            <a id="gis-online" class="btn btn-outline-secondary btn-sm" href="rep-online.php" hidden>
                                <i class="bi bi-people me-1" aria-hidden="true"></i><?= $gis_escape(t('gis', 'onlineUsers')) ?>
                            </a>
                            <a id="gis-accounting" class="btn btn-outline-secondary btn-sm" hidden>
                                <i class="bi bi-bar-chart me-1" aria-hidden="true"></i><?= $gis_escape(t('gis', 'accounting')) ?>
                            </a>
                            <a id="gis-compare" class="btn btn-outline-secondary btn-sm" href="acct-hotspot-compare.php" hidden>
                                <i class="bi bi-columns-gap me-1" aria-hidden="true"></i><?= $gis_escape(t('gis', 'compare')) ?>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </aside>
    </div>
    <noscript><p class="alert alert-info mt-2"><?= $gis_escape(t('gis', 'noJavascript')) ?></p></noscript>
</section>
<?php if (!empty($successMsg)): ?>
<div class="toast-container position-fixed bottom-0 end-0 p-3 gis-toast-container">
    <div id="gis-toast" class="toast show align-items-center text-bg-success border-0 shadow" role="status" aria-live="polite" aria-atomic="true" data-auto-dismiss="4000">
        <div class="d-flex">
            <div class="toast-body d-flex align-items-center gap-2">
                <i class="bi bi-check-circle-fill flex-shrink-0" aria-hidden="true"></i>
                <span><?= $successMsg ?></span>
            </div>
            <button id="gis-toast-close" type="button" class="btn-close btn-close-white me-2 m-auto" aria-label="Close"></button>
        </div>
    </div>
</div>
<?php endif; ?>
<div class="modal fade" id="gis-help-modal" tabindex="-1" aria-labelledby="gis-help-modal-label" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h1 class="modal-title fs-5" id="gis-help-modal-label"><?= $gis_escape($title) ?> :: help</h1>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <?= t('gis', 'help') ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<?php if ($gis_loaded): ?>
<script id="gis-data" type="application/json"><?= $gis_json ?></script>
<?php endif; ?>
<?php
include $configValues['OPERATORS_INCLUDE_CONFIG'] . '/logging.php';
print_footer_and_html_epilogue('', $extra_js);
