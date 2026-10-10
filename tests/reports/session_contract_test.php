<?php
require_once __DIR__.'/../../app/operators/library/reports/session_service.php';
$checks=0;
function verify($condition,$message){global $checks;if(!$condition){throw new RuntimeException($message);} $checks++;}
$settings=dalo_reports_settings(array('CONFIG_REPORTS_DB_TIMEZONE'=>'UTC'));
$f=dalo_reports_filters(array(),$settings);
verify($f['status']==='all'&&$f['limit']===25,'safe defaults');
verify($settings['interval']===0,'unknown freshness default');
verify(dalo_reports_settings(array('CONFIG_REPORTS_INTERIM_INTERVAL'=>'300','CONFIG_REPORTS_FRESHNESS_TOLERANCE'=>'2'))['interval']===300,'scalar config_read normalization');
verify(dalo_reports_filters(array('detail'=>'9007199254740993'),$settings)['detail']===9007199254740993,'BIGINT detail identifier');
foreach(array(
    array('username'=>array('x')),array('sort'=>'username; DROP TABLE radacct'),array('direction'=>'DESC;'),
    array('status'=>'online'),array('timezone'=>'Mars/Base'),array('startdate'=>'2026-02-30'),
    array('startdate'=>'2026-10-10','enddate'=>'2026-10-09'),array('page'=>'-1'),array('page'=>'1.5'),
    array('page'=>'0001'),array('page'=>'100001'),array('limit'=>'10000'),array('detail'=>'-1'),
    array('ip'=>'192.0.2.999'),array('format'=>'xml'),array('sql'=>'SELECT'),array('username'=>"x\0y"),
    array('enddate'=>'1969-12-31'),array('nas'=>str_repeat('a',257))
) as $bad){try{dalo_reports_filters($bad,$settings);throw new RuntimeException('accepted malformed filters');}catch(InvalidArgumentException $e){verify(true,'reject invalid filters');}}
verify(dalo_reports_like('a%_!b')==='%a!%!_!!b%','literal wildcards');
$f=dalo_reports_filters(array('timezone'=>'Europe/Paris','startdate'=>'2026-03-29','enddate'=>'2026-03-29'),$settings);
verify(dalo_reports_boundary($f['startdate'],false,$f,$settings)==='2026-03-28 23:00:00','DST start');
verify(dalo_reports_boundary($f['enddate'],true,$f,$settings)==='2026-03-29 22:00:00','DST exclusive end');
foreach(array(array('CONFIG_REPORTS_INTERIM_INTERVAL'=>-1),array('CONFIG_REPORTS_INTERIM_INTERVAL'=>'300x'),array('CONFIG_REPORTS_FRESHNESS_TOLERANCE'=>0),array('CONFIG_REPORTS_NAS_INTERVALS'=>array('not-ip'=>300)))as$bad){try{dalo_reports_settings($bad);throw new RuntimeException('accepted malformed config');}catch(InvalidArgumentException $e){verify(true,'reject bad settings');}}
verify(dalo_reports_csv_cell('=1+1')==="'=1+1",'CSV formula');
verify(dalo_reports_csv_cell("\t@SUM(1)")==="'\t@SUM(1)",'CSV tab');
verify(dalo_reports_csv_cell('ordinary')==='ordinary','CSV plain');
verify(dalo_reports_csv_cell('9007199254740993')==='9007199254740993','counter precision');
$stream=fopen('php://temp','w+');dalo_reports_write_csv($stream,array(array('username'=>'=1+1','password'=>'secret','nas_secret'=>'secret')));rewind($stream);$csv=stream_get_contents($stream);
verify(strpos($csv,'secret')===false&&strpos($csv,'password')===false,'export field allowlist');
verify(strpos($csv,"'=1+1")!==false,'CSV escaping applied');
verify(dalo_reports_timestamp(null,$settings,$f)===null,'missing timestamp');
verify(dalo_reports_timestamp('0000-00-00 00:00:00',$settings,$f)===null,'zero timestamp');
echo "PASS $checks contract checks\n";
