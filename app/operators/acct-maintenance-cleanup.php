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
 *             Miguel García <miguelvisgarcia@gmail.com>
 *             Filippo Lauria <filippo.lauria@iit.cnr.it>
 *
 *********************************************************************************************************
 */
include('library/checklogin.php');
$operator = $_SESSION['operator_user'];
include('library/check_operator_perm.php');
include_once('lang/main.php');
include('../common/includes/validation.php');
include('../common/includes/layout.php');
require_once('library/acct_maintenance.php');
$logAction = $logDebugSQL = '';
$log = 'visited page: ';
$preview = null;
$notice = '';
$active = 'close';
$filter = ['action' => 'close', 'scope' => 'username', 'value' => ''];
include('../common/includes/db_open.php');
$dbSocket->setErrorHandling(PEAR_ERROR_RETURN);
// Bind confirmation to the operator and actual accounting backend, not only IDs.
$context = hash('sha256', serialize([$_SESSION['operator_id'], $mydbHost, $mydbPort,
                                   $mydbName, $configValues['CONFIG_DB_TBL_RADACCT']]));
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!is_string($_POST['csrf_token'] ?? null) || !is_string($_SESSION['csrf_token'] ?? null) ||
            !dalo_check_csrf_token($_POST['csrf_token'])) {
            throw new InvalidArgumentException('CSRF');
        }
        $filter = dalo_maintenance_filter($_POST);
        $active = $filter['action'];
        $step = $_POST['step'] ?? null;
        if ($step === 'preview') {
            unset($_SESSION['acct_maintenance_preview']);
            $preview = dalo_maintenance_preview($dbSocket, $configValues['CONFIG_DB_TBL_RADACCT'], $filter);
            $preview['context'] = $context;
            $_SESSION['acct_maintenance_preview'] = $preview;
            if (!$preview['rows']) {
                $notice = t('maintenance', 'empty');
            }
        } elseif ($step === 'confirm') {
            $stored = $_SESSION['acct_maintenance_preview'] ?? null;
            // Consume even failed confirmations; replays require a fresh preview.
            unset($_SESSION['acct_maintenance_preview']);
            if (!$stored || $stored['filter'] !== $filter || $stored['context'] !== $context ||
                !is_string($_POST['confirmation'] ?? null) ||
                !hash_equals($stored['token'], $_POST['confirmation'])) {
                throw new InvalidArgumentException('Confirmation');
            }
            $result = dalo_maintenance_apply($dbSocket, $configValues['CONFIG_DB_TBL_RADACCT'], $stored);
            $notice = sprintf(t('maintenance', 'result'), t('maintenance', $active),
                              $result['affected'], $result['skipped'], $result['failed']);
            $logAction = sprintf('Open-session maintenance action=%s scope=%s value=%s affected=%d skipped=%d failed=%d on page: ',
                                 $active, $filter['scope'], json_encode($filter['value']),
                                 $result['affected'], $result['skipped'], $result['failed']);
        } else {
            throw new InvalidArgumentException('Step');
        }
    } else {
        // GET is prefill only; returning/reloading invalidates an older preview.
        unset($_SESSION['acct_maintenance_preview']);
        if (isset($_GET['username'])) {
            $filter = dalo_maintenance_filter(['action' => 'close', 'scope' => 'username', 'value' => $_GET['username']]);
        }
    }
} catch (InvalidArgumentException $e) {
    unset($_SESSION['acct_maintenance_preview']);
    $failureMsg = t('maintenance', 'invalid');
    $logAction = 'Rejected open-session maintenance request on page: ';
} catch (RuntimeException $e) {
    unset($_SESSION['acct_maintenance_preview']);
    $failureMsg = t('maintenance', 'error');
}
include('../common/includes/db_close.php');
function maintenance_escape($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
function maintenance_text($key) {
    return maintenance_escape(t('maintenance', $key));
}
$title = t('maintenance', 'title');
print_html_prologue($title, $langCode);
print_title_and_help($title, t('maintenance', 'help'));
include_once('include/management/actionMessages.php');
if ($notice !== '') {
    echo '<div class="alert alert-info" role="status">' . maintenance_escape($notice) . '</div>';
}
$csrfToken = dalo_csrf_token();
?>
<div class="alert alert-warning"><?= maintenance_text('openWarning') ?></div>
<p><?= maintenance_text('dateHelp') ?></p>
<ul class="nav nav-tabs mb-3" role="tablist">
<?php foreach (['close', 'delete'] as $action): ?>
<li class="nav-item" role="presentation"><button type="button" class="nav-link <?= $active === $action ? 'active' : '' ?>" id="<?= $action ?>-tab" data-bs-toggle="tab" data-bs-target="#<?= $action ?>-panel" role="tab" aria-controls="<?= $action ?>-panel" aria-selected="<?= $active === $action ? 'true' : 'false' ?>"><?= maintenance_text($action) ?></button></li>
<?php endforeach; ?>
</ul>
<div class="tab-content">
<?php foreach (['close', 'delete'] as $action): ?>
<section id="<?= $action ?>-panel" class="tab-pane fade <?= $active === $action ? 'show active' : '' ?>" role="tabpanel" aria-labelledby="<?= $action ?>-tab" tabindex="0">
<h2 class="h5"><?= maintenance_text($action) ?></h2>
<div class="alert <?= $action === 'delete' ? 'alert-danger' : 'alert-secondary' ?>"><?= maintenance_text($action . 'Help') ?></div>
<form method="post" action="acct-maintenance-cleanup.php" class="maintenance-filter mb-3">
<input type="hidden" name="csrf_token" value="<?= maintenance_escape($csrfToken) ?>">
<input type="hidden" name="action" value="<?= $action ?>">
<input type="hidden" name="step" value="preview">
<div class="row g-2 align-items-end">
<div class="col-md-4"><label class="form-label" for="<?= $action ?>-scope"><?= maintenance_text('scope') ?></label>
<select class="form-select" name="scope" id="<?= $action ?>-scope">
<?php foreach (['username', 'date'] as $scope): ?>
<option value="<?= $scope ?>" <?= $active === $action && $filter['scope'] === $scope ? 'selected' : '' ?>><?= maintenance_text($scope) ?></option>
<?php endforeach; ?>
</select></div>
<div class="col-md-5"><label class="form-label" for="<?= $action ?>-value"><?= maintenance_text('value') ?></label>
<input class="form-control" id="<?= $action ?>-value" name="value" maxlength="253" required value="<?= $active === $action ? maintenance_escape($filter['value']) : '' ?>" aria-describedby="filter-help"></div>
<div class="col-md-3"><button class="btn btn-primary" type="submit"><?= maintenance_text('preview') ?></button></div>
</div>
</form>
</section>
<?php endforeach; ?>
</div>
<p id="filter-help" class="text-muted"><?= maintenance_text('filterHelp') ?></p>
<?php if ($preview && $preview['rows']): ?>
<section id="maintenance-preview" class="border rounded p-3 my-3">
<h2 class="h5"><?= maintenance_text('preview') ?>: <?= maintenance_text($active) ?></h2>
<p><?= maintenance_text($filter['scope']) ?>: <strong><?= maintenance_escape($filter['value']) ?></strong></p>
<p><?= maintenance_escape(sprintf(t('maintenance', 'count'), $preview['total'], count($preview['rows']), DALO_MAINTENANCE_LIMIT)) ?></p>
<p><?= maintenance_text('concurrency') ?></p>
<div class="table-responsive"><table class="table table-sm table-striped"><thead><tr>
<?php foreach (['id', 'username', 'nas', 'start', 'activity', 'seconds', 'input', 'output'] as $column): ?>
<th scope="col"><?= maintenance_text($column) ?></th>
<?php endforeach; ?>
</tr></thead><tbody>
<?php foreach ($preview['rows'] as $row): ?>
<tr><?php foreach (['radacctid', 'username', 'nasipaddress', 'acctstarttime', 'acctupdatetime', 'acctsessiontime', 'acctinputoctets', 'acctoutputoctets'] as $column): ?>
<td><?= maintenance_escape($row[$column] ?? '—') ?></td>
<?php endforeach; ?></tr>
<?php endforeach; ?>
</tbody></table></div>
<p class="text-muted"><?= maintenance_text('activityHelp') ?></p>
<form method="post" action="acct-maintenance-cleanup.php" id="maintenance-confirm">
<input type="hidden" name="csrf_token" value="<?= maintenance_escape($csrfToken) ?>">
<input type="hidden" name="step" value="confirm">
<input type="hidden" name="confirmation" value="<?= maintenance_escape($preview['token']) ?>">
<?php foreach ($filter as $key => $value): ?>
<input type="hidden" name="<?= maintenance_escape($key) ?>" value="<?= maintenance_escape($value) ?>">
<?php endforeach; ?>
<button type="submit" class="btn <?= $active === 'delete' ? 'btn-danger' : 'btn-warning' ?>"><?= maintenance_text($active . 'Confirm') ?></button>
</form>
</section>
<script>
// Editing a visible filter or changing action hides the old confirmation.
// The server independently requires an exact match to its one-use snapshot.
const invalidateMaintenancePreview = () => {
    const preview = document.getElementById('maintenance-preview');
    if (preview) preview.remove();
};
document.querySelectorAll('.maintenance-filter').forEach(form => {
    form.addEventListener('input', invalidateMaintenancePreview);
    form.addEventListener('change', invalidateMaintenancePreview);
});
document.querySelectorAll('[data-bs-toggle="tab"]').forEach(tab => {
    tab.addEventListener('show.bs.tab', invalidateMaintenancePreview);
});
</script>
<?php endif;
print_back_to_previous_page();
include('include/config/logging.php');
print_footer_and_html_epilogue();
