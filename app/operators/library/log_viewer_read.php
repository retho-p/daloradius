<?php
/** Bounded, read-only access to the four configured log sources. No shell commands. */
if (strpos($_SERVER['PHP_SELF'] ?? '', '/library/log_viewer_read.php') !== false) {
    http_response_code(404); exit;
}
require_once __DIR__ . '/operator_acl_read.php';

function dalo_log_sources() {
    return array(
        'daloradius' => array('label' => 'daloRADIUS', 'permission' => 'rep_logs_daloradius', 'setting' => 'CONFIG_LOG_FILE', 'paths' => array(), 'types' => array('QUERY', 'NOTICE', 'INSERT', 'SELECT')),
        'radius' => array('label' => 'RADIUS', 'permission' => 'rep_logs_radius', 'setting' => 'CONFIG_RADIUSLOG_FILE', 'paths' => array('/var/log/freeradius/radius.log', '/usr/local/var/log/radius/radius.log', '/var/log/radius/radius.log'), 'types' => array('Auth', 'Info', 'Error')),
        'system' => array('label' => 'System', 'permission' => 'rep_logs_system', 'setting' => 'CONFIG_SYSLOG_FILE', 'paths' => array('/var/log/syslog', '/var/log/messages'), 'types' => array()),
        'boot' => array('label' => 'Boot', 'permission' => 'rep_logs_boot', 'setting' => 'CONFIG_BOOTLOG_FILE', 'paths' => array('/var/log/boot', '/var/log/dmesg', '/usr/local/var/log/dmesg'), 'types' => array()),
    );
}
function dalo_log_allowed_sources(PDO $pdo, array $config, $operatorId) {
    $allowed = array();
    foreach (dalo_log_sources() as $key => $source) {
        if (dalo_operator_acl_allowed($pdo, $config, $operatorId, $source['permission'])) { $allowed[$key] = $source; }
    }
    return $allowed;
}
function dalo_log_filters(array $input, $source) {
    $definitions = dalo_log_sources();
    if (!is_string($source) || !isset($definitions[$source])) { throw new InvalidArgumentException('Invalid log source'); }
    foreach ($input as $key => $value) {
        if (!in_array($key, array('source', 'q', 'type', 'count', 'format'), true) || !is_string($value) || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw new InvalidArgumentException('Invalid log filter');
        }
    }
    $q = $input['q'] ?? '';
    $type = $input['type'] ?? '';
    $count = $input['count'] ?? '100';
    $format = $input['format'] ?? 'html';
    if (strlen($q) > 200 || ($type !== '' && !in_array($type, $definitions[$source]['types'], true)) ||
        !preg_match('/\A[1-9][0-9]{0,3}\z/', $count) || (int) $count > 1000 || !in_array($format, array('html', 'text'), true)) {
        throw new InvalidArgumentException('Invalid log filter');
    }
    return array('source' => $source, 'q' => $q, 'type' => $type, 'count' => $count, 'format' => $format);
}
/** Select only paths supplied by trusted configuration or fixed source defaults. */
function dalo_log_read(array $config, array $filters) {
    $filters = dalo_log_filters($filters, $filters['source'] ?? null);
    $definition = dalo_log_sources()[$filters['source']];
    $paths = $definition['paths'];
    $configured = $config[$definition['setting']] ?? '';
    if (is_string($configured) && $configured !== '') { array_unshift($paths, $configured); }
    $result = array('state' => 'missing', 'path' => '', 'lines' => array(), 'matches' => 0, 'bounded' => false,
                    'limited' => false, 'modified' => null, 'read_at' => date('Y-m-d H:i:s T'), 'message' => '');
    if (!$paths) { $result['state'] = 'unconfigured'; return $result; }
    foreach (array_unique($paths) as $path) {
        if (!is_string($path) || $path === '' || $path[0] !== '/' || strpos($path, "\0") !== false || !is_file($path)) { continue; }
        if (is_readable($path)) { $result['path'] = $path; break; }
        $result['state'] = 'unreadable'; $result['path'] = $path; return $result;
    }
    if ($result['path'] === '') { return $result; }
    $stream = @fopen($result['path'], 'rb');
    if ($stream === false) { $result['state'] = 'unreadable'; return $result; }
    try {
        $stat = fstat($stream);
        if ($stat === false) { $result['state'] = 'error'; return $result; }
        $result['modified'] = date('Y-m-d H:i:s T', $stat['mtime']);
        $size = $stat['size'];
        if ($size === 0) { $result['state'] = 'empty'; return $result; }
        // A fixed 2 MiB window bounds I/O and memory even for huge files or sparse matches.
        $limit = 2 * 1024 * 1024;
        $offset = max(0, $size - $limit);
        if (fseek($stream, $offset) !== 0) { $result['state'] = 'error'; return $result; }
        $text = stream_get_contents($stream, $limit);
        if ($text === false) { $result['state'] = 'error'; return $result; }
        $result['bounded'] = $offset > 0;
        if ($offset > 0) {
            // Discard the partial first line, not a fragment presented as a complete entry.
            $newline = strpos($text, "\n");
            $text = $newline === false ? '' : substr($text, $newline + 1);
        }
    } finally { fclose($stream); }
    // Also cap line-array overhead for files containing millions of short lines.
    $parts = $text === '' ? array() : explode("\n", strrev($text), 20001);
    if (count($parts) === 20001) { array_pop($parts); $result['bounded'] = true; }
    $lines = array_reverse(array_map('strrev', $parts));
    unset($parts, $text);
    if ($lines && end($lines) === '') { array_pop($lines); }
    $matches = array();
    for ($i = count($lines) - 1; $i >= 0; $i--) {
        if (($filters['q'] === '' || stripos($lines[$i], $filters['q']) !== false) &&
            ($filters['type'] === '' || preg_match('/(?<![A-Za-z0-9_])' . preg_quote($filters['type'], '/') . '(?![A-Za-z0-9_])/i', $lines[$i]))) {
            if (count($matches) === (int) $filters['count']) { $result['limited'] = true; break; }
            $matches[$i] = true;
        }
    }
    $result['matches'] = count($matches);
    if (!$matches) { $result['state'] = 'no_matches'; return $result; }
    $selected = array();
    $withContext = $filters['q'] !== '' || $filters['type'] !== '';
    foreach ($matches as $i => $unused) {
        $start = $withContext ? max(0, $i - 2) : $i;
        $end = $withContext ? min(count($lines) - 1, $i + 2) : $i;
        for ($j = $start; $j <= $end; $j++) { $selected[$j] = true; }
    }
    ksort($selected, SORT_NUMERIC);
    $previous = null;
    foreach ($selected as $i => $unused) {
        if ($previous !== null && $i > $previous + 1) { $result['lines'][] = array('text' => '[... omitted lines ...]', 'context' => true, 'gap' => true); }
        $result['lines'][] = array('text' => rtrim($lines[$i], "\r"), 'context' => $withContext && !isset($matches[$i]), 'gap' => false);
        $previous = $i;
    }
    $result['state'] = 'ok';
    return $result;
}
