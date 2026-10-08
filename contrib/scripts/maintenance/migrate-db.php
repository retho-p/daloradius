<?php
/** CLI runner for the bundled MariaDB schema migrations. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$root = dirname(__DIR__, 3);
$configFile = $root . '/app/common/includes/daloradius.conf.php';
$apply = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--apply') {
        $apply = true;
    } elseif (strpos($arg, '--config=') === 0) {
        $configFile = substr($arg, 9);
    } elseif ($arg === '--help') {
        echo "Usage: php migrate-db.php [--apply] [--config=/path/to/daloradius.conf.php]\nDefault: read-only preview. Back up your database before --apply.\n";
        exit(0);
    } else {
        fwrite(STDERR, "Unknown argument. Use --help.\n");
        exit(2);
    }
}
$db = null;
$locked = false;
$writesStarted = false;
$current = 'preflight';
try {
    if (!extension_loaded('mysqli') || !is_readable($configFile)) {
        throw new RuntimeException('PHP mysqli and a readable trusted configuration are required.');
    }
    $configValues = array();
    require $configFile;
    if (!in_array($configValues['CONFIG_DB_ENGINE'] ?? '', array('mysql', 'mysqli'), true)) {
        throw new RuntimeException('Only MariaDB configurations are supported.');
    }
    foreach (array('CONFIG_DB_TBL_DALOOPERATORS' => 'operators', 'CONFIG_DB_TBL_DALOOPERATORS_ACL' => 'operators_acl',
                    'CONFIG_DB_TBL_DALOOPERATORS_ACL_FILES' => 'operators_acl_files', 'CONFIG_DB_TBL_DALOUSERINFO' => 'userinfo') as $key => $table) {
        if (($configValues[$key] ?? $table) !== $table) {
            throw new RuntimeException('Bundled SQL requires the standard table names.');
        }
    }
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = new mysqli($configValues['CONFIG_DB_HOST'], $configValues['CONFIG_DB_USER'],
                     $configValues['CONFIG_DB_PASS'], $configValues['CONFIG_DB_NAME'],
                     (int) ($configValues['CONFIG_DB_PORT'] ?? 3306));
    $db->set_charset('utf8mb4');
    $version = $db->query('SELECT VERSION()')->fetch_row()[0];
    if (stripos($version, 'MariaDB') === false) {
        throw new RuntimeException('The bundled SQL is MariaDB-specific; MySQL is not supported.');
    }
    // Reject a wrong/incomplete installation before history creation or DDL.
    // This does not make subsequent DDL transactional or predict every error.
    $requiredColumns = array(
        'operators' => array('id', 'username', 'password'),
        'operators_acl' => array('operator_id', 'file', 'access'),
        'operators_acl_files' => array('file', 'category', 'section'),
        'userinfo' => array('portalloginpassword'),
    );
    $metadata = $db->query("SELECT c.TABLE_NAME, c.COLUMN_NAME
        FROM information_schema.COLUMNS c JOIN information_schema.TABLES t
          ON t.TABLE_SCHEMA=c.TABLE_SCHEMA AND t.TABLE_NAME=c.TABLE_NAME
        WHERE c.TABLE_SCHEMA=DATABASE() AND t.TABLE_TYPE='BASE TABLE'
          AND c.TABLE_NAME IN ('operators','operators_acl','operators_acl_files','userinfo')");
    $present = array();
    while ($column = $metadata->fetch_assoc()) {
        $present[$column['TABLE_NAME']][] = $column['COLUMN_NAME'];
    }
    $metadata->free();
    foreach ($requiredColumns as $table => $columns) {
        if (array_diff($columns, $present[$table] ?? array())) {
            throw new RuntimeException('Required daloRADIUS table or columns missing: ' . $table . '. No migrations started.');
        }
    }
    $files = glob($root . '/contrib/db/migrations/*.sql');
    sort($files, SORT_STRING);
    if (!$files) {
        throw new RuntimeException('No bundled migrations found.');
    }
    // Read all inputs before any write; only execute trusted repository SQL.
    $scripts = array();
    foreach ($files as $file) {
        $sql = file_get_contents($file);
        if ($sql === false || trim($sql) === '') {
            throw new RuntimeException('Unreadable or empty migration.');
        }
        $scripts[basename($file)] = array($sql, hash('sha256', $sql));
    }
    if ($apply) {
        $locked = (int) $db->query("SELECT GET_LOCK(CONCAT('dalo-migrate:', LEFT(SHA2(DATABASE(),256),40)),0)")->fetch_row()[0] === 1;
        if (!$locked) {
            throw new RuntimeException('Another migration runner holds the lock.');
        }
    }
    $exists = (int) $db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='daloradius_schema_migrations'")->fetch_row()[0] === 1;
    $history = array();
    if ($exists) {
        $result = $db->query('SELECT filename, checksum, status FROM daloradius_schema_migrations');
        while ($row = $result->fetch_assoc()) {
            $history[$row['filename']] = $row;
        }
    }
    // Validate the complete history before starting any pending migration.
    foreach ($history as $name => $row) {
        if (!isset($scripts[$name]) || !hash_equals($row['checksum'], $scripts[$name][1]) || $row['status'] !== 'applied') {
            throw new RuntimeException('Migration history differs or an earlier run was interrupted. Inspect the history and database before continuing.');
        }
    }
    if ($apply) {
        $writesStarted = true;
        $db->query("CREATE TABLE IF NOT EXISTS daloradius_schema_migrations (filename VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, checksum CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, status VARCHAR(16) NOT NULL, applied_at DATETIME NULL) ENGINE=InnoDB");
        $db->query("SET SESSION sql_mode = CONCAT_WS(',', NULLIF(@@sql_mode,''), 'STRICT_ALL_TABLES')");
    }
    foreach ($scripts as $name => list($sql, $checksum)) {
        $current = $name;
        if (isset($history[$name])) {
            echo "SKIP $name\n";
            continue;
        }
        if (!$apply) {
            echo "PENDING $name\n";
            continue;
        }
        $statement = $db->prepare("INSERT INTO daloradius_schema_migrations (filename,checksum,status) VALUES (?,?,'running')");
        $statement->bind_param('ss', $name, $checksum);
        $statement->execute();
        // LDAP permits NULL passwords. Do not reapply the older NOT NULL
        // change if its widening requirement is already satisfied.
        $satisfied = false;
        if ($name === '2025-03-operator-password-hashing.sql') {
            $column = $db->query("SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='operators' AND COLUMN_NAME='password' AND DATA_TYPE='varchar'")->fetch_row();
            $satisfied = $column && (int) $column[0] >= 95;
        }
        if (!$satisfied) {
            $db->multi_query($sql);
            do {
                $result = $db->store_result();
                if ($result instanceof mysqli_result) {
                    $result->free();
                }
                if (!$db->more_results()) {
                    break;
                }
                $db->next_result();
            } while (true);
        }
        $statement = $db->prepare("UPDATE daloradius_schema_migrations SET status='applied', applied_at=NOW() WHERE filename=?");
        $statement->bind_param('s', $name);
        $statement->execute();
        echo ($satisfied ? 'SATISFIED ' : 'APPLIED ') . "$name\n";
    }
    echo $apply ? "Migration run complete.\n" : "Preview only: no database writes. Back up, then use --apply.\n";
} catch (Throwable $error) {
    // Driver errors can contain data; do not print exception messages.
    $message = $error instanceof mysqli_sql_exception ? 'Database operation failed (code ' . $error->getCode() . ').' : $error->getMessage();
    fwrite(STDERR, "Stopped at $current: $message\n");
    fwrite(STDERR, $writesStarted
        ? "DDL may already be committed. Do not retry an interrupted migration without inspection.\n"
        : "No migration writes were started.\n");
    exit(1);
} finally {
    if ($db !== null) {
        // Closing this nonpersistent connection also releases GET_LOCK.
        $db->close();
    }
}
