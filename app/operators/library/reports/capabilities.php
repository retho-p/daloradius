<?php
/** Detect the schema being read, without assuming Docker and classic schemas match. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }

function dalo_reports_table(array $config, string $key): string {
    $value = $config[$key] ?? null;
    if (!is_string($value) || !preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $value)) { throw new InvalidArgumentException('Invalid reporting table'); }
    return '`' . $value . '`';
}

function dalo_reports_columns(PDO $pdo, string $table): array {
    return array_map('strtolower', $pdo->query('SHOW COLUMNS FROM ' . $table)->fetchAll(PDO::FETCH_COLUMN));
}

function dalo_reports_capabilities(PDO $pdo, array $config): array {
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') { throw new RuntimeException('Session Explorer currently requires MySQL/MariaDB'); }
    $table = dalo_reports_table($config, 'CONFIG_DB_TBL_RADACCT');
    $columns = dalo_reports_columns($pdo, $table);
    foreach (array('radacctid','username','acctstarttime','acctstoptime') as $required) {
        if (!in_array($required, $columns, true)) { throw new RuntimeException('Missing required accounting columns'); }
    }
    $nasTable = dalo_reports_table($config, 'CONFIG_DB_TBL_RADNAS');
    $exists = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
    $exists->execute(array(trim($nasTable, '`')));
    $nasColumns = $exists->fetchColumn() ? dalo_reports_columns($pdo, $nasTable) : array();
    return array('table' => $table, 'columns' => $columns, 'nas_table' => $nasTable,
        'nas_names' => in_array('nasipaddress',$columns,true) && in_array('nasname',$nasColumns,true) && in_array('shortname',$nasColumns,true),
        'last_accounting' => in_array('acctupdatetime',$columns,true),
        'ipv6' => in_array('framedipv6address',$columns,true));
}
