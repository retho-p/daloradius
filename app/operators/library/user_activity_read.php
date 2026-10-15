<?php
/** Read-only provider for the unified operator User activity reports. */
if (strpos($_SERVER['PHP_SELF'] ?? '', '/library/user_activity_read.php') !== false) {
    http_response_code(404);
    exit;
}

/** Return the fixed User activity views and their existing report ACLs. */
function dalo_activity_views()
{
    return array(
        'sessions' => array('label' => 'Sessions', 'permission' => 'rep_online'),
        'authentications' => array('label' => 'Authentications', 'permission' => 'rep_lastconnect'),
        'accounts' => array('label' => 'New accounts', 'permission' => 'rep_newusers'),
        'usage' => array('label' => 'Usage', 'permission' => 'rep_topusers'),
    );
}

/**
 * Validate and canonicalize request filters. page/per_page are integers;
 * every other returned filter value is a string. The date interval is
 * inclusive calendar days. Sessions in history mode are selected by START;
 * usage sums whole-session counters for sessions started in that interval.
 */
function dalo_activity_filters(array $input, string $view)
{
    $views = dalo_activity_views();
    if (!isset($views[$view])) {
        throw new InvalidArgumentException('Unsupported activity view');
    }
    $allowed = array('view','username','startdate','enddate','state','ip','mac','nas','reply','sort','direction','page','per_page');
    foreach ($input as $key => $value) {
        if (!is_string($key) || !in_array($key, $allowed, true) || is_array($value) || is_object($value) || is_resource($value)) {
            throw new InvalidArgumentException('Invalid activity filter');
        }
    }
    if (array_key_exists('view', $input) && (!is_string($input['view']) || $input['view'] !== $view)) {
        throw new InvalidArgumentException('Invalid activity view');
    }
    $result = array('view' => $view);
    foreach (array('username','ip','mac','nas','reply') as $key) {
        $value = $input[$key] ?? ($key === 'reply' ? 'Any' : '');
        if (!is_string($value) || strlen($value) > 253 || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw new InvalidArgumentException('Invalid activity filter');
        }
        $result[$key] = $value;
    }
    if (!in_array($result['reply'], array('Any','Access-Accept','Access-Reject'), true)) {
        throw new InvalidArgumentException('Invalid activity reply filter');
    }
    $today = new DateTimeImmutable('today');
    $defaultStart = $today->modify('-6 days')->format('Y-m-d');
    foreach (array('startdate' => $defaultStart, 'enddate' => $today->format('Y-m-d')) as $key => $default) {
        $value = $input[$key] ?? ($key === 'reply' ? 'Any' : '');
        if ($value === '') { $value = $default; }
        if (!is_string($value) || !preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value)) {
            throw new InvalidArgumentException('Invalid activity date');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Invalid activity date');
        }
        $result[$key] = $value;
    }
    if ($result['startdate'] > $result['enddate']) {
        throw new InvalidArgumentException('Activity start date must not follow end date');
    }
    $state = $input['state'] ?? ($view === 'sessions' ? 'open' : '');
    if (!is_string($state) || ($view === 'sessions' && !in_array($state, array('open','history'), true)) ||
        ($view !== 'sessions' && $state !== '')) {
        throw new InvalidArgumentException('Invalid activity state');
    }
    $result['state'] = $state;
    $sortDefaults = array('sessions'=>'started','authentications'=>'attempted','accounts'=>'created','usage'=>'download');
    $sortAllowed = array(
        'sessions'=>array('username','ip','mac','nas','started','ended','duration','upload','download','status'),
        'authentications'=>array('username','reply','attempted'),
        'accounts'=>array('username','fullname','created'),
        'usage'=>array('username','duration','upload','download','sessions'),
    );
    $sort = $input['sort'] ?? $sortDefaults[$view];
    $direction = $input['direction'] ?? 'desc';
    if (!is_string($sort) || !in_array($sort, $sortAllowed[$view], true) || !is_string($direction) || !in_array(strtolower($direction), array('asc','desc'), true)) {
        throw new InvalidArgumentException('Invalid activity ordering');
    }
    $result['sort'] = $sort;
    $result['direction'] = strtolower($direction);
    foreach (array('page'=>1,'per_page'=>25) as $key => $default) {
        $value = $input[$key] ?? $default;
        if (is_int($value)) { $value = (string)$value; }
        if (!is_string($value) || !preg_match('/\A[1-9]\d{0,8}\z/', $value)) {
            throw new InvalidArgumentException('Invalid activity pagination');
        }
        $number = (int)$value;
        if (($key === 'page' && $number > 10000000) || ($key === 'per_page' && $number > 100)) {
            throw new InvalidArgumentException('Invalid activity pagination');
        }
        $result[$key] = $number;
    }
    return $result;
}

/** Associate stable output keys with user-facing labels for a view. */
function dalo_activity_columns(string $view)
{
    $columns = array(
        'sessions'=>array('username'=>'Username','ip'=>'IP address','mac'=>'MAC address','nas'=>'NAS','started'=>'Started','ended'=>'Ended','duration'=>'Duration','upload'=>'Upload','download'=>'Download','status'=>'Status'),
        'authentications'=>array('username'=>'Username','reply'=>'Reply','attempted'=>'Attempted'),
        'accounts'=>array('username'=>'Username','fullname'=>'Name','created'=>'Created'),
        'usage'=>array('username'=>'Username','duration'=>'Duration','upload'=>'Upload','download'=>'Download','sessions'=>'Sessions'),
    );
    if (!isset($columns[$view])) { throw new InvalidArgumentException('Unsupported activity view'); }
    return $columns[$view];
}

/** Resolve configured MySQL table identifiers using a conservative whitelist. */
function dalo_activity_table(array $config, string $key)
{
    if (!array_key_exists($key, $config) || !is_string($config[$key]) || !preg_match('/\A[A-Za-z0-9_]{1,64}\z/', $config[$key])) {
        throw new InvalidArgumentException('Invalid activity table configuration');
    }
    return '`' . $config[$key] . '`';
}

/** Build safe predicates and execute one report plus a matching count. */
function dalo_activity_report(PDO $pdo, array $config, array $filters, bool $export = false)
{
    $view = $filters['view'] ?? '';
    $canonical = dalo_activity_filters($filters, $view);
    $tables = array(
        'ra'=>dalo_activity_table($config, 'CONFIG_DB_TBL_RADACCT'),
        'pa'=>dalo_activity_table($config, 'CONFIG_DB_TBL_RADPOSTAUTH'),
        'ui'=>dalo_activity_table($config, 'CONFIG_DB_TBL_DALOUSERINFO'),
    );
    $where = array(); $bind = array();
    $like = static function ($field, $key) use (&$where, &$bind, $canonical) {
        if ($canonical[$key] !== '') {
            $where[] = $field . " LIKE :f_" . $key . " ESCAPE '!'";
            $bind[':f_'.$key] = strtr($canonical[$key], array('!'=>'!!','%'=>'!%','_'=>'!_')) . '%';
        }
    };
    $period = static function ($column) use (&$where, &$bind, $canonical) {
        $where[] = $column . ' >= :period_start AND ' . $column . ' < DATE_ADD(:period_end, INTERVAL 1 DAY)';
        $bind[':period_start'] = $canonical['startdate']; $bind[':period_end'] = $canonical['enddate'];
    };
    $dateNote = 'Dates are inclusive calendar days.';
    if ($view === 'sessions' || $view === 'usage') {
        $like('ra.username','username'); $like('ra.framedipaddress','ip'); $like('ra.callingstationid','mac'); $like('ra.nasipaddress','nas');
        if (!($view === 'sessions' && $canonical['state'] === 'open')) { $period('ra.acctstarttime'); }
        if ($view === 'sessions' && $canonical['state'] === 'open') { $where[] = "(ra.acctstoptime IS NULL OR ra.acctstoptime='0000-00-00 00:00:00')"; $dateNote = 'Open accounting records, not confirmed live presence. Missing Stop messages can leave stale records. Dates are not applied in Open now.'; }
        if ($view === 'sessions' && $canonical['state'] === 'history') { $dateNote = 'History includes sessions whose START is within the inclusive date interval.'; }
        $source = " FROM {$tables['ra']} AS ra";
        if ($view === 'sessions') {
            $select = "SELECT ra.username AS username, ra.framedipaddress AS ip, ra.callingstationid AS mac, ra.nasipaddress AS nas, ra.acctstarttime AS started, ra.acctstoptime AS ended, ra.acctsessiontime AS duration, ra.acctinputoctets AS upload, ra.acctoutputoctets AS download, CASE WHEN ra.acctstoptime IS NULL OR ra.acctstoptime='0000-00-00 00:00:00' THEN 'open' ELSE 'ended' END AS status";
            $keys = array('username'=>'ra.username','ip'=>'ra.framedipaddress','mac'=>'ra.callingstationid','nas'=>'ra.nasipaddress','started'=>'ra.acctstarttime','ended'=>'ra.acctstoptime','duration'=>'ra.acctsessiontime','upload'=>'ra.acctinputoctets','download'=>'ra.acctoutputoctets','status'=>'ra.acctstoptime');
            $tie = 'ra.radacctid'; $note = $dateNote;
        } else {
            $select = 'SELECT ra.username AS username, COALESCE(SUM(ra.acctsessiontime),0) AS duration, COALESCE(SUM(ra.acctinputoctets),0) AS upload, COALESCE(SUM(ra.acctoutputoctets),0) AS download, COUNT(*) AS sessions';
            $keys = array('username'=>'username','duration'=>'duration','upload'=>'upload','download'=>'download','sessions'=>'sessions');
            $tie = 'username'; $note = 'Usage sums whole-session counters for sessions STARTED in the inclusive date interval; it is not traffic observed exactly within that interval.';
        }
    } elseif ($view === 'authentications') {
        $userColumn = (string)($config['FREERADIUS_VERSION'] ?? '3') === '1' ? 'user' : 'username';
        $dateColumn = $userColumn === 'user' ? 'date' : 'authdate';
        $like('pa.'.$userColumn,'username');
        if ($canonical['reply'] !== 'Any') { $where[] = 'pa.reply = :f_reply'; $bind[':f_reply'] = $canonical['reply']; }
        $period('pa.'.$dateColumn);
        $source = " FROM {$tables['pa']} AS pa";
        $select = "SELECT pa.$userColumn AS username, pa.reply AS reply, pa.$dateColumn AS attempted";
        $keys = array('username'=>'pa.'.$userColumn,'reply'=>'pa.reply','attempted'=>'pa.'.$dateColumn); $tie='pa.id'; $note='Authentication attempts are read from the post-authentication log; passwords are never selected.';
    } else {
        $like('ui.username','username'); $period('ui.CreationDate');
        $source = " FROM {$tables['ui']} AS ui";
        $select = "SELECT ui.username AS username, CONCAT(COALESCE(ui.firstname,''),' ',COALESCE(ui.lastname,'')) AS fullname, ui.CreationDate AS created";
        $keys = array('username'=>'ui.username','fullname'=>'fullname','created'=>'ui.CreationDate'); $tie='ui.id'; $note='One row per user-info account record.';
    }
    if ($where) { $source .= ' WHERE ' . implode(' AND ', $where); }
    $group = $view === 'usage' ? ' GROUP BY ra.username' : '';
    $sortExpr = $keys[$canonical['sort']];
    // Sort expressions come exclusively from the fixed per-view column map above.
    $order = ' ORDER BY '.$sortExpr.' '.$canonical['direction'].', '.$tie.' ASC';
    $countSql = 'SELECT COUNT(*) FROM ('.$select.$source.$group.') AS activity_count';
    $total = (int)dalo_activity_execute($pdo, $countSql, $bind)->fetchColumn();
    $limit = $export ? 10001 : $canonical['per_page'];
    $pages = max(1, (int) ceil($total / $canonical['per_page']));
    $page = min($canonical['page'], $pages);
    $offset = $export ? 0 : ($page - 1) * $canonical['per_page'];
    $rows = dalo_activity_execute($pdo, $select.$source.$group.$order.' LIMIT :activity_offset, :activity_limit', $bind + array(':activity_offset'=>$offset, ':activity_limit'=>$limit))->fetchAll(PDO::FETCH_ASSOC);
    $truncated = $export && count($rows) > 10000;
    if ($truncated) { $rows = array_slice($rows, 0, 10000); }
    return array('columns'=>dalo_activity_columns($view),'sortable'=>array_keys($keys),'rows'=>$rows,'total'=>$total,'page'=>$export ? 1 : $page,'pages'=>$export ? 1 : $pages,'note'=>$note,'truncated'=>$truncated);
}

/** Prepared statement executor; intentionally local to this read provider. */
function dalo_activity_execute(PDO $pdo, string $sql, array $bindings)
{
    $stmt = $pdo->prepare($sql);
    foreach ($bindings as $name => $value) {
        $stmt->bindValue($name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();
    return $stmt;
}
