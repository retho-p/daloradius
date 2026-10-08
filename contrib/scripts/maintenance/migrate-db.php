<?php
/** CLI runner for the bundled MariaDB schema migrations. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$root = dirname(__DIR__, 3);
$configFile = $root . '/app/common/includes/daloradius.conf.php';
$migrationsDir = $root . '/contrib/db/migrations';
$apply = false;
$help = false;
$colorMode = 'auto';
$output = function ($message, $tone = 'info', $stream = null) use (&$colorMode) {
    $stream = $stream ?? STDOUT;
    $noColor = getenv('NO_COLOR');
    $enabled = $colorMode === 'always' || ($colorMode === 'auto'
        && ($noColor === false || $noColor === '') && getenv('TERM') !== 'dumb'
        && function_exists('stream_isatty') && stream_isatty($stream));
    $colors = array('title' => '1;36', 'success' => '32', 'warning' => '33',
                    'error' => '31', 'info' => '36', 'muted' => '90');
    fwrite($stream, $enabled ? "\033[" . $colors[$tone] . 'm' . $message . "\033[0m\n" : $message . "\n");
};
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--apply') {
        $apply = true;
    } elseif (strpos($arg, '--config=') === 0) {
        $configFile = substr($arg, 9);
    } elseif (strpos($arg, '--migrations-dir=') === 0) {
        $migrationsDir = substr($arg, 17);
    } elseif ($arg === '--no-color') {
        $colorMode = 'never';
    } elseif (strpos($arg, '--color=') === 0) {
        $colorMode = substr($arg, 8);
        if (!in_array($colorMode, array('auto', 'always', 'never'), true)) {
            $colorMode = 'never';
            $output('Invalid color mode. Use auto, always or never.', 'error', STDERR);
            exit(2);
        }
    } elseif ($arg === '--help') {
        $help = true;
    } else {
        $output('Unknown argument. Use --help.', 'error', STDERR);
        exit(2);
    }
}
if ($help) {
    $output("Usage: php migrate-db.php [--apply] [--config=/path/to/daloradius.conf.php]\n"
        . "                         [--migrations-dir=/path/to/migrations]\n"
        . "                         [--color=auto|always|never] [--no-color]\n"
        . 'Default: read-only preview. Back up your database before --apply.');
    exit(0);
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
    if (!is_dir($migrationsDir) || !is_readable($migrationsDir)) {
        throw new RuntimeException('Migration directory is unavailable.');
    }
    $files = glob(rtrim($migrationsDir, '/') . '/*.sql');
    sort($files, SORT_STRING);
    if (!$files) {
        throw new RuntimeException('No bundled migrations found.');
    }
    // Read all inputs before any write; only execute trusted repository SQL.
    $scripts = array();
    foreach ($files as $file) {
        if (!preg_match('/\A[A-Za-z0-9._-]+\.sql\z/D', basename($file))) {
            throw new RuntimeException('Invalid migration filename.');
        }
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
    $legacyChecksum = false;
    $legacyStatus = false;
    $legacyNames = array();
    if ($exists) {
        $columns = $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='daloradius_schema_migrations'")->fetch_all(MYSQLI_NUM);
        $columns = array_column($columns, 0);
        $legacyChecksum = !in_array('checksum', $columns, true) && in_array('sha256', $columns, true);
        $legacyStatus = !in_array('status', $columns, true);
        if (!in_array('filename', $columns, true) || !in_array('applied_at', $columns, true)
            || (!$legacyChecksum && !in_array('checksum', $columns, true))) {
            throw new RuntimeException('Unknown migration history layout.');
        }
        $result = $db->query('SELECT filename, ' . ($legacyChecksum ? 'sha256' : 'checksum')
            . ' AS checksum, ' . ($legacyStatus ? "'applied'" : 'status') . ' AS status FROM daloradius_schema_migrations');
        while ($row = $result->fetch_assoc()) {
            $original = $row['filename'];
            $name = strpos($original, 'contrib/db/migrations/') === 0 ? substr($original, 22) : $original;
            if (!preg_match('/\A[A-Za-z0-9._-]+\.sql\z/D', $name) || isset($history[$name])) {
                throw new RuntimeException('Invalid or duplicate migration history identity.');
            }
            if ($original !== $name) {
                $legacyNames[$original] = $name;
            }
            $row['filename'] = $name;
            $history[$name] = $row;
        }
    }
    // Validate the complete history before starting any pending migration.
    foreach ($history as $name => $row) {
        if (!isset($scripts[$name]) || !hash_equals($row['checksum'], $scripts[$name][1]) || $row['status'] !== 'applied') {
            throw new RuntimeException('Migration history differs or an earlier run was interrupted. Inspect the history and database before continuing.');
        }
    }
    $output('daloRADIUS database migrations', 'title');
    $output('Mode: ' . ($apply ? 'APPLY' : 'PREVIEW (read-only)'));
    $output('Database: ' . json_encode($configValues['CONFIG_DB_NAME'])
        . ' on ' . json_encode($configValues['CONFIG_DB_HOST']));
    if (!$history) {
        $output('No recorded history: SQL may have been applied manually. Pending means unrecorded, not necessarily missing.', 'warning');
    }
    if ($apply) {
        $writesStarted = true;
        // Adopt the former Bash helper's ledger. Validate all checksums first;
        // each intermediate layout remains recognizable after interruption.
        if ($legacyChecksum) {
            $db->query('ALTER TABLE daloradius_schema_migrations CHANGE COLUMN sha256 checksum CHAR(64) NOT NULL');
        }
        if ($exists && $legacyStatus) {
            $db->query("ALTER TABLE daloradius_schema_migrations ADD COLUMN status VARCHAR(16) NOT NULL DEFAULT 'applied'");
        }
        foreach ($legacyNames as $oldName => $newName) {
            $rename = $db->prepare('UPDATE daloradius_schema_migrations SET filename=? WHERE filename=?');
            $rename->bind_param('ss', $newName, $oldName);
            $rename->execute();
        }
        $db->query("CREATE TABLE IF NOT EXISTS daloradius_schema_migrations (filename VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, checksum CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, status VARCHAR(16) NOT NULL, applied_at DATETIME NULL) ENGINE=InnoDB");
        $db->query("SET SESSION sql_mode = CONCAT_WS(',', NULLIF(@@sql_mode,''), 'STRICT_ALL_TABLES')");
    }
    $counts = array('applied' => 0, 'satisfied' => 0, 'skipped' => 0, 'pending' => 0);
    foreach ($scripts as $name => list($sql, $checksum)) {
        $current = $name;
        if (isset($history[$name])) {
            $counts['skipped']++;
            $output("SKIP $name (already recorded)", 'muted');
            continue;
        }
        if (!$apply) {
            $counts['pending']++;
            $output("PENDING $name (not recorded; would run)", 'warning');
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
        $counts[$satisfied ? 'satisfied' : 'applied']++;
        $output(($satisfied ? 'SATISFIED ' : 'APPLIED ') . $name
            . ($satisfied ? ' (requirement already met; recorded)' : ' (SQL executed and recorded)'),
            $satisfied ? 'info' : 'success');
    }
    $output(sprintf('Summary: %d applied, %d already satisfied, %d skipped, %d pending.',
        $counts['applied'], $counts['satisfied'], $counts['skipped'], $counts['pending']), 'title');
    $output($apply ? 'Migration run complete.'
        : 'Preview only: no database writes. Back up, then use --apply.', $apply ? 'success' : 'warning');
} catch (Throwable $error) {
    // Driver errors can contain data; do not print exception messages.
    $message = $error instanceof mysqli_sql_exception ? 'Database operation failed (code ' . $error->getCode() . ').' : $error->getMessage();
    $output("Stopped at $current: $message", 'error', STDERR);
    $output($writesStarted
        ? 'DDL may already be committed. Do not retry an interrupted migration without inspection.'
        : 'No migration writes were started.', 'warning', STDERR);
    exit(1);
} finally {
    if ($db !== null) {
        // Closing this nonpersistent connection also releases GET_LOCK.
        $db->close();
    }
}
