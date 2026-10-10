<?php
/** Additive, read-only RADIUS session investigation. */
require_once __DIR__ . '/../common/includes/config_read.php';
require __DIR__ . '/library/checklogin.php';
$operator_perm_file = 'rep_online';
$operator_perm_deny_http_status = 403;
require __DIR__ . '/library/check_operator_perm.php';
require_once __DIR__ . '/../common/includes/pdo_connection.php';
require_once __DIR__ . '/library/reports/session_service.php';
require_once __DIR__ . '/lang/main.php';
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { header('Allow: GET'); http_response_code(405); exit; }
try {
    $settings=dalo_reports_settings($configValues);
} catch(Throwable $error) { http_response_code(503); exit(t('reports_sessions','Unavailable')); }
try { $filters=dalo_reports_filters($_GET,$settings); }
catch(InvalidArgumentException $error) { http_response_code(400); exit(t('reports_sessions','InvalidFilters')); }
// Release the session lock before expensive read-only work and independent tab exports.
if (session_status()===PHP_SESSION_ACTIVE) { session_write_close(); }
$result=null; $failure=null;
try {
    $pdo=dalo_pdo_connect($configValues,$_SESSION['location_name']??'default');
    $caps=dalo_reports_capabilities($pdo,$configValues);
    if($filters['format']==='csv') {
        $rows=dalo_reports_csv($pdo,$filters,$settings,$caps);
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="radius-sessions.csv"');
        dalo_reports_write_csv(fopen('php://output','wb'),$rows); exit;
    }
    $result=dalo_reports_search($pdo,$filters,$settings,$caps);
    if($filters['detail']>0 && $result['detail']===null) { http_response_code(404); }
    if($filters['format']==='json') {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($result,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE); exit;
    }
} catch(DomainException $error) { http_response_code(422); $failure=t('reports_sessions','MissingCapability'); }
catch(LengthException $error) { http_response_code(422); $failure=t('reports_sessions','ExportLimit'); }
catch(Throwable $error) { error_log('Session Explorer read failed: '.get_class($error)); http_response_code(503); $failure=t('reports_sessions','Unavailable'); }
if($filters['format']!=='html') {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(array('error'=>$failure),JSON_INVALID_UTF8_SUBSTITUTE); exit;
}
require_once __DIR__ . '/../common/includes/layout.php';
function reports_h($value): string { return htmlspecialchars((string)($value??''),ENT_QUOTES,'UTF-8'); }
function reports_t(string $key): string { return reports_h(t('reports_sessions',$key)); }
function reports_url(array $filters,array $changes=array()): string {
    $args=array_merge($filters,$changes); unset($args['format']);
    if(isset($changes['format'])) { $args['format']=$changes['format']; }
    return 'rep-sessions.php?'.http_build_query($args,'','&',PHP_QUERY_RFC3986);
}
function reports_bytes($value): string {
    if($value===null) { return '—'; }
    $units=array('B','KiB','MiB','GiB','TiB','PiB'); $n=(float)$value; $i=0;
    while(abs($n)>=1024 && $i<count($units)-1) { $n/=1024; $i++; }
    return number_format($n,$i?2:0,'.',' ').' '.$units[$i];
}
$statusLabels=array('all'=>'All','recent'=>'Recent','stale'=>'Stale','ended'=>'Ended','unknown'=>'Unknown');
$statusColors=array('recent'=>'success','stale'=>'warning','ended'=>'secondary','unknown'=>'dark');
$css='.session-explorer{--report-line:#dce2e5}.session-explorer .form-control,.session-explorer .form-select,.session-explorer .btn,.session-explorer .card{border-radius:3px}.session-explorer .report-metric{border:1px solid var(--report-line);border-top:3px solid #647a72;padding:.65rem .8rem;color:inherit;text-decoration:none;min-width:120px;flex:1}.session-explorer .report-metric.active{background:#eef5f1;border-top-color:#23714e}.session-explorer .report-number{display:block;font:600 1.6rem monospace}.session-explorer table{font-size:.84rem}.session-explorer th{white-space:nowrap}.session-explorer .report-identity{font-family:monospace}.session-explorer .report-date{white-space:normal;font-size:.76rem;min-width:125px;max-width:140px}.session-explorer .report-detail{border:1px solid var(--report-line);border-left:4px solid #647a72;padding:1rem;margin-bottom:1rem}.session-explorer .report-detail dt{font-weight:500;color:#56626a}.session-explorer .report-detail dd{font-family:monospace;overflow-wrap:anywhere}.session-explorer .report-notes{font-size:.79rem;color:#59666d}.session-explorer .badge{border-radius:3px;font-weight:500}';
print_html_prologue(t('reports_sessions','Title'),$langCode,array(),array(),$css);
?>
<section class="session-explorer" aria-labelledby="session-title">
<header class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div><h1 class="h4 mb-1" id="session-title"><?= reports_t('Title') ?></h1><p class="text-secondary small mb-0"><?= reports_t('Subtitle') ?></p></div>
    <div class="d-flex gap-2"><a class="btn btn-sm btn-outline-secondary" href="<?= reports_h(reports_url($filters)) ?>"><?= reports_t('Refresh') ?></a><a class="btn btn-sm btn-outline-secondary" href="rep-online.php"><?= reports_t('Legacy') ?></a>
    <?php if($result!==null): ?><a class="btn btn-sm btn-outline-success" href="<?= reports_h(reports_url($filters,array('format'=>'csv','detail'=>0))) ?>"><?= reports_t('Export') ?></a><?php endif; ?></div>
</header>
<?php if(in_array($configValues['CONFIG_REPORTS_DEMO']??false,array(true,1,'1','true','yes'),true)): ?><div class="alert alert-info py-2 small" role="status"><?= reports_t('DemoNote') ?></div><?php endif; ?>
<form method="get" action="rep-sessions.php" class="border p-3 mb-3" aria-label="<?= reports_t('Filters') ?>">
    <div class="row g-2">
    <?php foreach(array('username'=>'Username','nas'=>'NAS','ip'=>'IP','station'=>'Station') as $key=>$label): ?>
        <div class="col-sm-6 col-xl-3"><label class="form-label small mb-1" for="filter-<?= $key ?>"><?= reports_t($label) ?></label><input class="form-control form-control-sm" id="filter-<?= $key ?>" name="<?= $key ?>" value="<?= reports_h($filters[$key]) ?>" maxlength="256" type="text"></div>
    <?php endforeach; ?>
        <div class="col-sm-6 col-xl-2"><label class="form-label small mb-1" for="filter-status"><?= reports_t('Status') ?></label><select class="form-select form-select-sm" name="status" id="filter-status"><?php foreach($statusLabels as $key=>$label): ?><option value="<?= $key ?>" <?= $filters['status']===$key?'selected':'' ?>><?= reports_t($label) ?></option><?php endforeach; ?></select></div>
    <?php foreach(array('startdate'=>'From','enddate'=>'Until') as $key=>$label): ?><div class="col-sm-6 col-xl-2"><label class="form-label small mb-1" for="filter-<?= $key ?>"><?= reports_t($label) ?></label><input class="form-control form-control-sm" type="date" id="filter-<?= $key ?>" name="<?= $key ?>" value="<?= reports_h($filters[$key]) ?>"></div><?php endforeach; ?>
        <div class="col-sm-6 col-xl-3"><label class="form-label small mb-1" for="filter-timezone"><?= reports_t('Timezone') ?></label><input class="form-control form-control-sm" id="filter-timezone" name="timezone" value="<?= reports_h($filters['timezone']) ?>" list="report-timezones"><datalist id="report-timezones"><?php foreach(array_unique(array($settings['db_timezone'],'UTC','Europe/Paris','America/New_York')) as $tz): ?><option value="<?= reports_h($tz) ?>"></option><?php endforeach; ?></datalist></div>
        <div class="col-sm-6 col-xl-1"><label class="form-label small mb-1" for="filter-limit"><?= reports_t('Rows') ?></label><select class="form-select form-select-sm" name="limit" id="filter-limit"><?php foreach(array(25,50,100) as $limit): ?><option <?= $filters['limit']===$limit?'selected':'' ?>><?= $limit ?></option><?php endforeach; ?></select></div>
        <div class="col-sm-12 col-xl-2 d-flex align-items-end gap-2"><button class="btn btn-sm btn-success" type="submit"><?= reports_t('Apply') ?></button><a class="btn btn-sm btn-outline-secondary" href="rep-sessions.php"><?= reports_t('Reset') ?></a></div>
        <input type="hidden" name="sort" value="<?= reports_h($filters['sort']) ?>"><input type="hidden" name="direction" value="<?= reports_h($filters['direction']) ?>">
    </div>
    <div class="report-notes mt-2"><?= reports_t('PeriodNote') ?></div>
</form>
<?php if($failure!==null): ?><div class="alert alert-danger" role="alert"><?= reports_h($failure) ?></div><?php endif; ?>
<?php if($result!==null): ?>
<div class="d-flex flex-wrap gap-2 mb-3" aria-label="<?= reports_t('Status') ?>">
<?php foreach($statusLabels as $key=>$label): ?><a class="report-metric <?= $filters['status']===$key?'active':'' ?>" href="<?= reports_h(reports_url($filters,array('status'=>$key,'page'=>1,'detail'=>0))) ?>"><span class="small"><?= reports_t($label) ?></span><span class="report-number"><?= (int)$result['summary'][$key] ?></span></a><?php endforeach; ?>
</div>
<div class="report-notes mb-3">
    <?= reports_t('FreshnessNote') ?> <?= reports_t('Observed') ?>: <span class="report-identity"><?= reports_h(dalo_reports_timestamp($result['observed_at'],$settings,$filters)) ?></span>.
    <?= reports_t('Interval') ?>: <?= (int)$settings['interval'] ?> s × <?= (int)$settings['tolerance'] ?>. <?= reports_t('OverrideNote') ?>
    <?php if(!$caps['last_accounting']): ?><strong><?= reports_t('NoUpdates') ?></strong><?php endif; ?>
</div>
<?php if($filters['detail']>0 && $result['detail']===null): ?><div class="alert alert-warning" role="alert"><?= reports_t('NotFound') ?></div><?php endif; ?>
<?php if($result['detail']!==null): $detail=$result['detail']; ?>
<aside class="report-detail" aria-labelledby="report-detail-title">
    <div class="d-flex justify-content-between mb-2"><h2 class="h6" id="report-detail-title"><?= reports_t('Detail') ?> #<?= reports_h($detail['session_id']) ?></h2><a href="<?= reports_h(reports_url($filters,array('detail'=>0))) ?>"><?= reports_t('Close') ?></a></div>
    <dl class="row small mb-0">
    <?php $detailKeys=array('username'=>'Username','radius_session_id'=>'RadiusID','accounting_unique_id'=>'UniqueID','nas_ip'=>'NAS','nas_name'=>'NASName','nas_port'=>'Port','nas_port_type'=>'PortType','ip'=>'IP','ipv6'=>'IPv6','calling_station'=>'Station','called_station'=>'CalledStation','started_at_display'=>'Started','last_accounting_at_display'=>'Updated','ended_at_display'=>'Stopped','duration_seconds'=>'Duration','input_bytes'=>'InputRaw','output_bytes'=>'OutputRaw','termination_cause'=>'Termination','expected_interval_seconds'=>'Interval','freshness_threshold_seconds'=>'Threshold'); foreach($detailKeys as $key=>$label): ?>
        <dt class="col-md-3"><?= reports_t($label) ?></dt><dd class="col-md-9"><?= reports_h($detail[$key]??t('reports_sessions','NotCollected')) ?></dd>
    <?php endforeach; ?>
    </dl><p class="report-notes mb-0"><?= reports_t('CounterNote') ?></p>
</aside>
<?php endif; ?>
<div class="table-responsive border"><table class="table table-sm table-hover align-middle mb-0">
<thead class="table-light"><tr>
<?php foreach(array('username'=>'Username','nas_ip'=>'NAS',null=>'IP','started_at'=>'Started','last_accounting_at'=>'Updated') as $key=>$label): ?>
<th><?php if($key !== ''): ?><a class="link-dark" href="<?= reports_h(reports_url($filters,array('sort'=>$key,'direction'=>$filters['sort']===$key&&$filters['direction']==='desc'?'asc':'desc','page'=>1,'detail'=>0))) ?>"><?= reports_t($label) ?><?= $filters['sort']===$key?($filters['direction']==='desc'?' ↓':' ↑'):'' ?></a><?php else: ?><?= reports_t($label) ?><?php endif; ?></th>
<?php endforeach; ?>
<th><?= reports_t('Status') ?></th><th class="text-end"><?= reports_t('Input') ?></th><th class="text-end"><?= reports_t('Output') ?></th><th><?= reports_t('Detail') ?></th>
</tr></thead><tbody>
<?php foreach($result['rows'] as $row): ?>
<tr data-session-id="<?= reports_h($row['session_id']) ?>">
    <td><a class="report-identity" href="<?= reports_h(reports_url($filters,array('username'=>$row['username'],'page'=>1,'detail'=>0))) ?>"><?= reports_h($row['username']) ?></a><div class="text-secondary small report-identity"><?= reports_h($row['calling_station']) ?></div></td>
    <td><a href="<?= reports_h(reports_url($filters,array('nas'=>$row['nas_ip']??'','page'=>1,'detail'=>0))) ?>"><?= reports_h($row['nas_name']?:($row['nas_ip']??'—')) ?></a><div class="text-secondary small report-identity"><?= reports_h($row['nas_ip']) ?></div></td>
    <td class="report-identity"><?= reports_h($row['ip']?:($row['ipv6']??'—')) ?></td>
    <td class="report-date"><?= reports_h($row['started_at_display']??'—') ?></td><td class="report-date"><?= reports_h($row['last_accounting_at_display']??'—') ?></td>
    <td><span class="badge text-bg-<?= $statusColors[$row['observed_status']] ?>"><?= reports_t($statusLabels[$row['observed_status']]) ?></span></td>
    <td class="text-end report-identity"><?= reports_h(reports_bytes($row['input_bytes'])) ?></td><td class="text-end report-identity"><?= reports_h(reports_bytes($row['output_bytes'])) ?></td>
    <td><a class="btn btn-sm btn-outline-secondary" aria-label="<?= reports_t('Detail') ?> #<?= reports_h($row['session_id']) ?>" href="<?= reports_h(reports_url($filters,array('detail'=>$row['session_id']))) ?>">#<?= reports_h($row['session_id']) ?></a></td>
</tr>
<?php endforeach; ?>
<?php if(!$result['rows']): ?><tr><td colspan="9" class="text-center text-secondary p-4"><?= reports_t('Empty') ?></td></tr><?php endif; ?>
</tbody></table></div>
<footer class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-2">
    <span class="small text-secondary"><?= reports_t('Total') ?>: <?= (int)$result['total'] ?> · <?= reports_t('Page') ?> <?= $filters['page'] ?> · <?= reports_t('ReadOnly') ?></span>
    <nav aria-label="<?= reports_t('Pagination') ?>" class="d-flex gap-2">
    <?php if($filters['page']>1): ?><a class="btn btn-sm btn-outline-secondary" href="<?= reports_h(reports_url($filters,array('page'=>$filters['page']-1,'detail'=>0))) ?>"><?= reports_t('Previous') ?></a><?php endif; ?>
    <?php if($filters['page']*$filters['limit']<$result['total']): ?><a class="btn btn-sm btn-outline-secondary" href="<?= reports_h(reports_url($filters,array('page'=>$filters['page']+1,'detail'=>0))) ?>"><?= reports_t('Next') ?></a><?php endif; ?>
    </nav>
</footer>
<p class="report-notes mt-3"><?= reports_t('CounterNote') ?></p>
<?php endif; ?>
</section>
<?php print_footer_and_html_epilogue();
