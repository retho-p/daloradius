<?php
/** Read-only Session Explorer request contract. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }

function dalo_reports_settings(array $config): array {
    $dbTimezone = $config['CONFIG_REPORTS_DB_TIMEZONE'] ?? date_default_timezone_get();
    if (!is_string($dbTimezone)) { throw new InvalidArgumentException('Invalid reporting timezone'); }
    new DateTimeZone($dbTimezone);
    $interval = $config['CONFIG_REPORTS_INTERIM_INTERVAL'] ?? 0;
    $tolerance = $config['CONFIG_REPORTS_FRESHNESS_TOLERANCE'] ?? 2;
    $numbers=array();
    foreach (array($interval, $tolerance) as $number) {
        // config_read.php normalizes scalar configuration values to strings.
        if (!is_int($number) && !(is_string($number) && preg_match('/\A[0-9]{1,5}\z/D',$number))) {
            throw new InvalidArgumentException('Invalid reporting interval');
        }
        $numbers[]=(int)$number;
    }
    list($interval,$tolerance)=$numbers;
    if ($interval < 0 || $interval > 86400 || $tolerance < 1 || $tolerance > 10) {
        throw new InvalidArgumentException('Invalid reporting interval');
    }
    $overrides = $config['CONFIG_REPORTS_NAS_INTERVALS'] ?? array();
    if (!is_array($overrides)) { throw new InvalidArgumentException('Invalid NAS reporting intervals'); }
    foreach ($overrides as $ip => $seconds) {
        if (!is_string($ip) || !filter_var($ip, FILTER_VALIDATE_IP) || !is_int($seconds) || $seconds < 0 || $seconds > 86400) {
            throw new InvalidArgumentException('Invalid NAS reporting interval');
        }
    }
    return array('db_timezone' => $dbTimezone, 'interval' => $interval, 'tolerance' => $tolerance, 'nas_intervals' => $overrides);
}

function dalo_reports_filters(array $input, array $settings): array {
    $allowed = array('username','nas','ip','station','status','startdate','enddate','timezone','sort','direction','page','limit','detail','format');
    foreach ($input as $key => $value) {
        if (!in_array($key, $allowed, true) || !is_string($value) || strlen($value) > 256 || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            throw new InvalidArgumentException('Invalid session filters');
        }
    }
    $filters = array();
    foreach (array('username','nas','ip','station','startdate','enddate') as $key) { $filters[$key] = trim($input[$key] ?? ''); }
    $filters['timezone'] = $input['timezone'] ?? $settings['db_timezone'];
    if (!in_array($filters['timezone'], DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true) && $filters['timezone'] !== 'UTC') {
        throw new InvalidArgumentException('Invalid timezone');
    }
    if ($filters['ip'] !== '' && !filter_var($filters['ip'], FILTER_VALIDATE_IP)) { throw new InvalidArgumentException('Invalid IP address'); }
    $filters['status'] = $input['status'] ?? 'all';
    if (!in_array($filters['status'], array('all','recent','stale','ended','unknown'), true)) { throw new InvalidArgumentException('Invalid status'); }
    $filters['sort'] = $input['sort'] ?? 'started_at';
    if (!in_array($filters['sort'], array('started_at','last_accounting_at','username','nas_ip','input_bytes','output_bytes','session_id'), true)) { throw new InvalidArgumentException('Invalid sort'); }
    $filters['direction'] = $input['direction'] ?? 'desc';
    if (!in_array($filters['direction'], array('asc','desc'), true)) { throw new InvalidArgumentException('Invalid direction'); }
    foreach (array('page' => 1, 'limit' => 25) as $key => $default) {
        $value = $input[$key] ?? (string)$default;
        if (!preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D', $value)) { throw new InvalidArgumentException('Invalid pagination'); }
        $filters[$key] = (int)$value;
    }
    if ($filters['page'] < 1 || $filters['page'] > 100000 || !in_array($filters['limit'], array(25,50,100), true)) { throw new InvalidArgumentException('Invalid pagination'); }
    $id=$input['detail'] ?? '0';
    $maxId=(string)PHP_INT_MAX;
    if (!preg_match('/\A(?:0|[1-9][0-9]{0,18})\z/D',$id) || strlen($id)>strlen($maxId) || (strlen($id)===strlen($maxId) && strcmp($id,$maxId)>0)) {
        throw new InvalidArgumentException('Invalid session identifier');
    }
    $filters['detail']=(int)$id;
    $filters['format'] = $input['format'] ?? 'html';
    if (!in_array($filters['format'], array('html','json','csv'), true)) { throw new InvalidArgumentException('Invalid format'); }
    foreach (array('startdate','enddate') as $key) {
        $value = $filters[$key];
        if ($value === '') { continue; }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone($filters['timezone']));
        if (!$date || $date->format('Y-m-d') !== $value || $date->format('Y') < '1970') { throw new InvalidArgumentException('Invalid date'); }
    }
    if ($filters['startdate'] !== '' && $filters['enddate'] !== '' && $filters['startdate'] > $filters['enddate']) { throw new InvalidArgumentException('Reversed date range'); }
    return $filters;
}

function dalo_reports_boundary(string $date, bool $exclusiveEnd, array $filters, array $settings): string {
    $boundary = new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone($filters['timezone']));
    if ($exclusiveEnd) { $boundary = $boundary->modify('+1 day'); }
    return $boundary->setTimezone(new DateTimeZone($settings['db_timezone']))->format('Y-m-d H:i:s');
}

function dalo_reports_like(string $value): string {
    return '%' . strtr($value, array('!' => '!!', '%' => '!%', '_' => '!_')) . '%';
}
