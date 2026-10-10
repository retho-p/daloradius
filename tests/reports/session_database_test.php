<?php
require_once __DIR__.'/../../app/common/includes/config_read.php';
require_once __DIR__.'/../../app/common/includes/pdo_connection.php';
require_once __DIR__.'/../../app/operators/library/reports/session_service.php';
$checks=0;
function verify($condition,$message){global $checks;if(!$condition){throw new RuntimeException($message);} $checks++;}
$pdo=dalo_pdo_connect($configValues);
$caps=dalo_reports_capabilities($pdo,$configValues);
$pdo->exec('CREATE TEMPORARY TABLE report_session_fixture LIKE '.$caps['table']);
$pdo->exec('CREATE TEMPORARY TABLE report_nas_fixture (nasname VARCHAR(128), shortname VARCHAR(32))');
$pdo->exec("INSERT INTO report_nas_fixture VALUES ('192.0.2.10','demo-a'),('192.0.2.10','demo-duplicate')");
$caps['table']='`report_session_fixture`';$caps['nas_table']='`report_nas_fixture`';$caps['nas_names']=true;
$settings=dalo_reports_settings(array('CONFIG_REPORTS_DB_TIMEZONE'=>'UTC','CONFIG_REPORTS_INTERIM_INTERVAL'=>300,'CONFIG_REPORTS_NAS_INTERVALS'=>array('192.0.2.30'=>0)));
$now='2026-10-10 12:00:00';
$insert=$pdo->prepare('INSERT INTO report_session_fixture (acctuniqueid,username,nasipaddress,acctstarttime,acctupdatetime,acctstoptime,callingstationid,framedipaddress,acctinputoctets,acctoutputoctets) VALUES (?,?,?,?,?,?,?,?,?,?)');
$records=array(
    array('recent','alice','192.0.2.10','2026-10-10 10:00:00','2026-10-10 11:59:00',null),
    array('threshold','alice','192.0.2.10','2026-10-10 10:00:00','2026-10-10 11:50:00',null),
    array('stale','bob','192.0.2.10','2026-10-10 10:00:00','2026-10-10 11:49:59',null),
    array('missing','carol','192.0.2.10','2026-10-10 10:00:00',null,null),
    array('future','carol','192.0.2.10','2026-10-10 10:00:00','2026-10-10 12:01:00',null),
    array('before','carol','192.0.2.10','2026-10-10 10:00:00','2026-10-10 09:00:00',null),
    array('nointerval','dave','192.0.2.30','2026-10-10 10:00:00','2026-10-10 11:59:00',null),
    array('ended','erin','192.0.2.10','2026-10-10 10:00:00','2026-10-10 10:50:00','2026-10-10 11:00:00'),
    array('zero','literal%_!','192.0.2.99','2026-10-10 10:00:00','2026-10-10 11:59:00','0000-00-00 00:00:00'),
    array('emptyupdate','carol','192.0.2.10','2026-10-10 10:00:00','0000-00-00 00:00:00',null),
    array('endboundary','outside','192.0.2.10','2026-10-11 00:00:00',null,null)
);
foreach($records as$r){$insert->execute(array_merge($r,array('AA-BB-CC','198.51.100.10','9007199254740993','1024')));}
$f=dalo_reports_filters(array('startdate'=>'2026-10-10','enddate'=>'2026-10-10'),$settings);
$r=dalo_reports_search($pdo,$f,$settings,$caps,$now);
verify($r['total']===10,'inclusive/exclusive period');
verify($r['summary']===array('all'=>10,'recent'=>3,'stale'=>1,'ended'=>1,'unknown'=>5),'status classification boundaries');
verify(count($r['rows'])===10,'duplicate NAS does not multiply rows');
verify($r['rows'][0]['input_bytes']==='9007199254740993'||$r['rows'][0]['input_bytes']===9007199254740993,'counter precision');
$firstIds=array_column($r['rows'],'session_id');
verify($firstIds===array_column(dalo_reports_search($pdo,$f,$settings,$caps,$now)['rows'],'session_id'),'deterministic sorting');
$f['status']='stale';$r=dalo_reports_search($pdo,$f,$settings,$caps,$now);
verify($r['total']===1&&count($r['rows'])===1&&$r['rows'][0]['username']==='bob','status filters');
verify($r['summary']['all']===10,'summary ignores only selected status');
$f['status']='all';$f['username']='%_!';$r=dalo_reports_search($pdo,$f,$settings,$caps,$now);
verify($r['total']===1&&$r['rows'][0]['username']==='literal%_!','literal LIKE wildcards');
$id=(int)$r['rows'][0]['session_id'];$f['detail']=$id;
verify(dalo_reports_search($pdo,$f,$settings,$caps,$now)['detail']['username']==='literal%_!','detail lookup');
$f['username']='alice';verify(dalo_reports_search($pdo,$f,$settings,$caps,$now)['detail']===null,'detail search scope');
$f['detail']=0;$f['username']='';$f['nas']='demo-a';verify(dalo_reports_search($pdo,$f,$settings,$caps,$now)['total']===8,'NAS names');
$f['nas']='';$f['ip']='198.51.100.10';verify(dalo_reports_search($pdo,$f,$settings,$caps,$now)['total']===10,'IP filter');
$f['ip']='';$f['station']='AA-BB';verify(dalo_reports_search($pdo,$f,$settings,$caps,$now)['total']===10,'station filter');
$f['station']='';$f['page']=2;verify(dalo_reports_search($pdo,$f,$settings,$caps,$now)['rows']===array(),'empty deep page');
$f['page']=1;$query=dalo_reports_query($f,$settings,$caps,$now);
$a=dalo_reports_rows($pdo,$query,$f,4,0);$b=dalo_reports_rows($pdo,$query,$f,4,4);
verify(count(array_unique(array_merge(array_column($a,'session_id'),array_column($b,'session_id'))))===8,'nonoverlapping pagination');
$pdo->exec('CREATE TEMPORARY TABLE report_session_minimal (radacctid BIGINT PRIMARY KEY, username VARCHAR(64), acctstarttime DATETIME, acctstoptime DATETIME)');
$pdo->exec("INSERT INTO report_session_minimal VALUES (1,'minimal','2026-10-10 10:00:00',NULL)");
$minimalConfig=$configValues;$minimalConfig['CONFIG_DB_TBL_RADACCT']='report_session_minimal';
$minimal=dalo_reports_capabilities($pdo,$minimalConfig);
verify(!$minimal['last_accounting']&&!$minimal['ipv6']&&!$minimal['nas_names'],'optional capabilities');
$r=dalo_reports_search($pdo,$f,$settings,$minimal,$now);
verify($r['total']===1&&$r['rows'][0]['observed_status']==='unknown'&&$r['rows'][0]['input_bytes']===null,'missing columns never zero');
$f['ip']='2001:db8::1';try{dalo_reports_search($pdo,$f,$settings,$minimal,$now);throw new RuntimeException('missing IPv6 silently accepted');}catch(DomainException$e){verify(true,'missing IP capability');}
$digits='(SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9)';
$pdo->exec("INSERT INTO report_session_fixture(acctuniqueid,username,nasipaddress,acctstarttime) SELECT MD5(CONCAT('export-',a.n,b.n,c.n,d.n,e.n)), 'bulk.export', '192.0.2.10', '2026-10-10 10:00:00' FROM $digits a CROSS JOIN $digits b CROSS JOIN $digits c CROSS JOIN $digits d CROSS JOIN $digits e LIMIT 10001");
$f['ip']='';$f['username']='bulk.export';
try{dalo_reports_csv($pdo,$f,$settings,$caps);throw new RuntimeException('silently truncated oversized CSV');}catch(LengthException$e){verify(true,'oversized CSV refuses truncation');}
echo "PASS $checks MariaDB fixture checks (temporary tables only)\n";
