<?php
/** Shared query predicates for HTML, JSON, details and CSV. No accounting writes. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }
require_once __DIR__ . '/filters.php';
require_once __DIR__ . '/capabilities.php';

function dalo_reports_query(array $filters, array $settings, array $caps, string $now): array {
    $bindings = array(':observed_at' => $now);
    $fields = array('radacctid'=>'session_id','username'=>'username','acctsessionid'=>'radius_session_id',
        'acctuniqueid'=>'accounting_unique_id','nasipaddress'=>'nas_ip','nasportid'=>'nas_port',
        'nasporttype'=>'nas_port_type','acctstarttime'=>'started_at','acctupdatetime'=>'last_accounting_at',
        'acctstoptime'=>'ended_at','acctsessiontime'=>'duration_seconds','acctinputoctets'=>'input_bytes',
        'acctoutputoctets'=>'output_bytes','framedipaddress'=>'ip','framedipv6address'=>'ipv6',
        'callingstationid'=>'calling_station','calledstationid'=>'called_station','acctterminatecause'=>'termination_cause');
    $select = array();
    foreach ($fields as $column => $alias) {
        $select[] = (in_array($column, $caps['columns'], true) ? 'r.`'.$column.'`' : 'NULL') . ' AS `' . $alias . '`';
    }
    $join = '';
    if ($caps['nas_names']) {
        $select[] = 'n.shortname AS nas_name';
        // A non-unique NAS configuration must never multiply accounting rows.
        $join = ' LEFT JOIN (SELECT nasname, MIN(shortname) AS shortname FROM '.$caps['nas_table'].' GROUP BY nasname) n ON n.nasname=r.nasipaddress';
    } else { $select[] = 'NULL AS nas_name'; }
    $interval = (string)$settings['interval'];
    if (in_array('nasipaddress',$caps['columns'],true) && $settings['nas_intervals']) {
        $interval = 'CASE r.nasipaddress';
        foreach ($settings['nas_intervals'] as $ip => $seconds) {
            $key = ':interval_nas_' . count($bindings);
            $bindings[$key] = $ip;
            $interval .= ' WHEN '.$key.' THEN '.$seconds;
        }
        $interval .= ' ELSE '.$settings['interval'].' END';
    }
    $select[] = '(' . $interval . ') AS expected_interval_seconds';
    $base = 'SELECT '.implode(', ', $select).' FROM '.$caps['table'].' r'.$join;
    $closed = "q.ended_at IS NOT NULL AND q.ended_at <> '0000-00-00 00:00:00'";
    $unknown = "q.expected_interval_seconds = 0 OR q.last_accounting_at IS NULL OR q.last_accounting_at='0000-00-00 00:00:00' OR q.started_at IS NULL OR q.started_at='0000-00-00 00:00:00' OR q.last_accounting_at < q.started_at OR q.last_accounting_at > clock.observed_at";
    $status = "CASE WHEN $closed THEN 'ended' WHEN $unknown THEN 'unknown' WHEN TIMESTAMPDIFF(SECOND,q.last_accounting_at,clock.observed_at) <= q.expected_interval_seconds * ".$settings['tolerance']." THEN 'recent' ELSE 'stale' END";
    $source = '(SELECT q.*, clock.observed_at, '.$status.' AS observed_status FROM ('.$base.') q CROSS JOIN (SELECT CAST(:observed_at AS DATETIME) AS observed_at) clock) s';
    $conditions = array();
    foreach (array('username'=>'username', 'station'=>'calling_station') as $key => $column) {
        if ($filters[$key] !== '') {
            if ($key === 'station' && !in_array('callingstationid',$caps['columns'],true)) { throw new DomainException('Station information is not collected'); }
            $conditions[] = 's.'.$column.' LIKE :'.$key." ESCAPE '!'";
            $bindings[':'.$key] = dalo_reports_like($filters[$key]);
        }
    }
    if ($filters['nas'] !== '') {
        if (!in_array('nasipaddress',$caps['columns'],true)) { throw new DomainException('NAS information is not collected'); }
        $conditions[] = "(s.nas_ip LIKE :nas_ip ESCAPE '!' OR s.nas_name LIKE :nas_name ESCAPE '!')";
        $bindings[':nas_ip'] = $bindings[':nas_name'] = dalo_reports_like($filters['nas']);
    }
    if ($filters['ip'] !== '') {
        $ipv6 = strpos($filters['ip'], ':') !== false;
        $column = $ipv6 ? 'ipv6' : 'ip';
        if (!in_array($ipv6 ? 'framedipv6address' : 'framedipaddress',$caps['columns'],true)) { throw new DomainException('Requested IP information is not collected'); }
        $conditions[] = 's.'.$column.' = :client_ip';
        $bindings[':client_ip'] = $filters['ip'];
    }
    if ($filters['status'] !== 'all') { $conditions[] = 's.observed_status = :status'; $bindings[':status'] = $filters['status']; }
    foreach (array('startdate'=>'>=', 'enddate'=>'<') as $key=>$operator) {
        if ($filters[$key] !== '') {
            $conditions[] = 's.started_at '.$operator.' :'.$key;
            $bindings[':'.$key] = dalo_reports_boundary($filters[$key], $key==='enddate', $filters, $settings);
        }
    }
    return array('source'=>$source, 'where'=>$conditions ? ' WHERE '.implode(' AND ', $conditions) : '', 'bindings'=>$bindings);
}

function dalo_reports_execute(PDO $pdo, string $sql, array $bindings): PDOStatement {
    $stmt = $pdo->prepare($sql);
    foreach ($bindings as $key=>$value) { $stmt->bindValue($key,$value,is_int($value)?PDO::PARAM_INT:PDO::PARAM_STR); }
    $stmt->execute();
    return $stmt;
}

function dalo_reports_summary(PDO $pdo, array $query): array {
    $rows = dalo_reports_execute($pdo, 'SELECT observed_status, COUNT(*) AS total FROM '.$query['source'].$query['where'].' GROUP BY observed_status', $query['bindings'])->fetchAll(PDO::FETCH_ASSOC);
    $result = array('all'=>0,'recent'=>0,'stale'=>0,'ended'=>0,'unknown'=>0);
    foreach ($rows as $row) { $result[$row['observed_status']] = (int)$row['total']; $result['all'] += (int)$row['total']; }
    return $result;
}

function dalo_reports_rows(PDO $pdo, array $query, array $filters, int $limit, int $offset): array {
    $bindings = $query['bindings'];
    $bindings[':row_limit']=$limit; $bindings[':row_offset']=$offset;
    // filters are normalized by the request contract, never raw request values.
    if (!in_array($filters['sort'], array('started_at','last_accounting_at','username','nas_ip','input_bytes','output_bytes','session_id'),true) || !in_array($filters['direction'],array('asc','desc'),true) || $limit<1 || $limit>10001 || $offset<0) { throw new InvalidArgumentException('Invalid row window'); }
    $order = ' ORDER BY s.`'.$filters['sort'].'` '.$filters['direction'];
    if ($filters['sort'] !== 'session_id') { $order .= ', s.session_id '.$filters['direction']; }
    return dalo_reports_execute($pdo,'SELECT s.* FROM '.$query['source'].$query['where'].$order.' LIMIT :row_limit OFFSET :row_offset',$bindings)->fetchAll(PDO::FETCH_ASSOC);
}

function dalo_reports_detail(PDO $pdo, array $query, int $id): ?array {
    $bindings=$query['bindings']; $bindings[':detail_id']=$id;
    // Detail retains the active search scope; an out-of-scope id is not a match.
    $where=$query['where'].($query['where']!==''?' AND ':' WHERE ').'s.session_id=:detail_id';
    $row=dalo_reports_execute($pdo,'SELECT s.* FROM '.$query['source'].$where,$bindings)->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}
