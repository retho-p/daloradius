<?php
/** Read model: named session fields and explicit observation semantics. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }
require_once __DIR__ . '/session_repository.php';

function dalo_reports_timestamp($value, array $settings, array $filters): ?string {
    if ($value === null || $value === '' || strncmp((string)$value,'0000-',5)===0) { return null; }
    try {
        return (new DateTimeImmutable((string)$value,new DateTimeZone($settings['db_timezone'])))->setTimezone(new DateTimeZone($filters['timezone']))->format('Y-m-d H:i:s P');
    } catch (Throwable $error) { return null; }
}

function dalo_reports_present(array $row, array $settings, array $filters): array {
    // JSON numbers cannot represent every BIGINT identifier/counter exactly.
    foreach(array('session_id','input_bytes','output_bytes','duration_seconds') as $key) {
        if($row[$key]!==null){$row[$key]=(string)$row[$key];}
    }
    foreach (array('started_at','last_accounting_at','ended_at','observed_at') as $key) {
        $row[$key.'_display'] = dalo_reports_timestamp($row[$key],$settings,$filters);
    }
    $row['freshness_threshold_seconds'] = (int)$row['expected_interval_seconds'] * $settings['tolerance'];
    $row['db_timezone'] = $settings['db_timezone'];
    $row['display_timezone'] = $filters['timezone'];
    return $row;
}

function dalo_reports_search(PDO $pdo, array $filters, array $settings, array $caps, ?string $now=null): array {
    $now=$now ?? (new DateTimeImmutable('now',new DateTimeZone($settings['db_timezone'])))->format('Y-m-d H:i:s');
    $query=dalo_reports_query($filters,$settings,$caps,$now);
    $owned=!$pdo->inTransaction();
    if ($owned) { $pdo->beginTransaction(); }
    try {
        $summaryFilters=$filters; $summaryFilters['status']='all';
        $summary=dalo_reports_summary($pdo,dalo_reports_query($summaryFilters,$settings,$caps,$now));
        $total=$summary[$filters['status']];
        $rows=dalo_reports_rows($pdo,$query,$filters,$filters['limit'],($filters['page']-1)*$filters['limit']);
        $detail=$filters['detail']>0?dalo_reports_detail($pdo,$query,$filters['detail']):null;
        if ($owned) { $pdo->commit(); }
    } catch (Throwable $error) {
        if ($owned && $pdo->inTransaction()) { $pdo->rollBack(); }
        throw $error;
    }
    return array('filters'=>$filters,'summary'=>$summary,'total'=>$total,'rows'=>array_map(static function($row) use($settings,$filters){return dalo_reports_present($row,$settings,$filters);},$rows),
        'detail'=>$detail?dalo_reports_present($detail,$settings,$filters):null,'observed_at'=>$now,
        'capabilities'=>array('last_accounting'=>$caps['last_accounting'],'ipv6'=>$caps['ipv6'],'nas_names'=>$caps['nas_names']),
        'semantics'=>array('period'=>'sessions_started_in_period','counters'=>'whole_session_nas_counters','db_timezone'=>$settings['db_timezone'],
            'display_timezone'=>$filters['timezone'],'default_expected_interval_seconds'=>$settings['interval'],'tolerance'=>$settings['tolerance']));
}

function dalo_reports_csv_cell($value): string {
    $text=(string)($value ?? '');
    if (preg_match('/\A(?:[\t\r\n]|\s*[=+\-@])/u',$text)) { return "'".$text; }
    return $text;
}

function dalo_reports_csv(PDO $pdo, array $filters, array $settings, array $caps): array {
    $now=(new DateTimeImmutable('now',new DateTimeZone($settings['db_timezone'])))->format('Y-m-d H:i:s');
    $query=dalo_reports_query($filters,$settings,$caps,$now);
    $rows=dalo_reports_rows($pdo,$query,$filters,10001,0);
    if (count($rows)>10000) { throw new LengthException('Narrow the search to at most 10000 sessions before exporting'); }
    return array_map(static function($row)use($settings,$filters){return dalo_reports_present($row,$settings,$filters);},$rows);
}

function dalo_reports_write_csv($stream, array $rows): void {
    $keys=array('session_id','radius_session_id','accounting_unique_id','username','nas_ip','nas_name','nas_port','ip','ipv6',
        'calling_station','called_station','started_at','last_accounting_at','ended_at','duration_seconds','input_bytes','output_bytes',
        'termination_cause','observed_status','observed_at','expected_interval_seconds','freshness_threshold_seconds','db_timezone','display_timezone');
    fputcsv($stream,$keys,',','"','');
    foreach($rows as $row) {
        $cells=array(); foreach($keys as $key){$cells[]=dalo_reports_csv_cell($row[$key]??null);}
        fputcsv($stream,$cells,',','"','');
    }
}
