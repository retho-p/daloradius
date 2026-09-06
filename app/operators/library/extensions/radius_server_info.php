<?php
/*
 *********************************************************************************************************
 * daloRADIUS - RADIUS Web Platform
 * Copyright (C) 2007 - Liran Tal <liran@lirantal.com> All Rights Reserved.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
 *
 *********************************************************************************************************
 *
 * Description:    Displays service status for local installations and
 *                 connectivity status for the official Docker deployment.
 *
 * Authors:        Liran Tal <liran@lirantal.com>
 *                 Filippo Lauria <filippo.lauria@iit.cnr.it>
 *
 *********************************************************************************************************
 */

// Prevent this file from being directly accessed.
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    header("Location: ../../index.php");
    exit;
}

/**
 * Detect whether the application is running in a container.
 */
function dalo_radius_is_container() {
    return file_exists('/.dockerenv') ||
           file_exists('/run/.containerenv') ||
           getenv('DOCKER_CONTAINER') !== false;
}

/**
 * Check a local process and then its systemd service aliases.
 *
 * Process names and service names differ between Debian (freeradius,
 * mariadb, ssh) and RHEL-family systems (radiusd, mariadb, sshd).
 */
function dalo_radius_check_local_service($daemon_names, $service_names = array()) {
    if (!function_exists('exec')) {
        return false;
    }

    foreach ($daemon_names as $daemon) {
        $output = array();
        $result_code = 1;
        exec(sprintf('pgrep -x %s 2>/dev/null', escapeshellarg($daemon)), $output, $result_code);
        if ($result_code === 0) {
            return true;
        }
    }

    $service_names = !empty($service_names) ? $service_names : $daemon_names;
    foreach ($service_names as $service) {
        $output = array();
        $result_code = 1;
        exec(sprintf('systemctl is-active --quiet %s 2>/dev/null', escapeshellarg($service)), $output, $result_code);
        if ($result_code === 0) {
            return true;
        }
    }

    return false;
}

/**
 * Probe a RADIUS server with the configured daloRADIUS test endpoint.
 *
 * A response proves that the server is reachable from the web container.
 * Docker uses the restricted Status-Server listener; local installations are
 * checked through their local daemon/service names instead.
 */
function dalo_radius_check_network_radius($config_values, $is_container) {
    if (!function_exists('exec')) {
        return false;
    }

    $radclient_path = array();
    $radclient_result = 1;
    exec('(command -v radclient || which radclient) 2>/dev/null', $radclient_path, $radclient_result);
    if ($radclient_result !== 0 || empty($radclient_path[0])) {
        return false;
    }

    $server = !empty($config_values['CONFIG_MAINT_TEST_USER_RADIUSSERVER'])
        ? trim($config_values['CONFIG_MAINT_TEST_USER_RADIUSSERVER'])
        : ($is_container ? 'radius' : '127.0.0.1');
    $port = !empty($config_values['CONFIG_MAINT_TEST_USER_RADIUSPORT'])
        ? intval($config_values['CONFIG_MAINT_TEST_USER_RADIUSPORT'])
        : ($is_container ? 18122 : 1812);
    $secret = !empty($config_values['CONFIG_MAINT_TEST_USER_RADIUSSECRET'])
        ? $config_values['CONFIG_MAINT_TEST_USER_RADIUSSECRET']
        : 'testing123';

    if ($server === '' || $port < 1 || $port > 65535) {
        return false;
    }

    $target = (filter_var($server, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false)
        ? sprintf('[%s]:%d', $server, $port)
        : sprintf('%s:%d', $server, $port);
    $command_type = $is_container ? 'status' : 'auth';
    $probe = $is_container
        ? 'FreeRADIUS-Statistics-Type = 1'
        : 'User-Name = "daloRADIUS-status-probe", User-Password = "daloRADIUS-status-probe"';
    $command = sprintf(
        "printf '%%s\\n' %s | %s -r 1 -t 1 %s %s %s 2>&1",
        escapeshellarg($probe),
        escapeshellarg($radclient_path[0]),
        escapeshellarg($target),
        escapeshellarg($command_type),
        escapeshellarg($secret)
    );

    $output = array();
    $result_code = 1;
    exec($command, $output, $result_code);
    $output_string = implode("\n", $output);

    return preg_match(
        '/(?:Received\\s+)?(?:Status-Server-Response|Access-(?:Accept|Reject|Challenge))/i',
        $output_string
    ) === 1;
}

/**
 * Check the configured database without terminating the status page on a
 * connection failure. The parent page does not open $dbSocket, so a short
 * non-fatal connection is needed for Docker and remote database deployments.
 */
function dalo_radius_check_database($db_socket, $config_values) {
    if (is_object($db_socket) && method_exists($db_socket, 'query') && class_exists('DB')) {
        try {
            $result = $db_socket->query('SELECT 1');
            return !DB::isError($result);
        } catch (Throwable $exception) {
            return false;
        }
    }

    if (empty($config_values['CONFIG_DB_ENGINE']) ||
        !isset($config_values['CONFIG_DB_USER'], $config_values['CONFIG_DB_PASS'],
               $config_values['CONFIG_DB_HOST'], $config_values['CONFIG_DB_PORT'],
               $config_values['CONFIG_DB_NAME'])) {
        return false;
    }

    // The configured Docker and manual-install path uses mysqli. Connect
    // directly instead of building a PEAR DSN: passwords containing URL
    // characters can otherwise produce a DB object with no live mysqli link.
    if (strtolower($config_values['CONFIG_DB_ENGINE']) === 'mysqli' && function_exists('mysqli_init')) {
        $connection = null;
        try {
            mysqli_report(MYSQLI_REPORT_OFF);
            $connection = mysqli_init();
            if ($connection === false || !mysqli_real_connect(
                $connection,
                $config_values['CONFIG_DB_HOST'],
                $config_values['CONFIG_DB_USER'],
                $config_values['CONFIG_DB_PASS'],
                $config_values['CONFIG_DB_NAME'],
                intval($config_values['CONFIG_DB_PORT'])
            )) {
                return false;
            }

            $result = mysqli_query($connection, 'SELECT 1');
            mysqli_close($connection);
            return $result !== false;
        } catch (Throwable $exception) {
            if ($connection instanceof mysqli) {
                mysqli_close($connection);
            }
            return false;
        }
    }

    if (!class_exists('DB')) {
        @include_once('DB.php');
    }
    if (!class_exists('DB')) {
        return false;
    }

    $dsn = sprintf(
        '%s://%s:%s@%s:%s/%s',
        $config_values['CONFIG_DB_ENGINE'],
        $config_values['CONFIG_DB_USER'],
        $config_values['CONFIG_DB_PASS'],
        $config_values['CONFIG_DB_HOST'],
        $config_values['CONFIG_DB_PORT'],
        $config_values['CONFIG_DB_NAME']
    );

    try {
        $connection = DB::connect($dsn);
        if (DB::isError($connection)) {
            return false;
        }

        $connection->setErrorHandling(PEAR_ERROR_RETURN);
        $result = $connection->query('SELECT 1');
        $healthy = !DB::isError($result);
        $connection->disconnect();
        return $healthy;
    } catch (Throwable $exception) {
        return false;
    }
}

$is_container = dalo_radius_is_container();

$status_freeradius = $is_container
    ? dalo_radius_check_network_radius($configValues ?? array(), true)
    : dalo_radius_check_local_service(array('freeradius', 'radiusd'), array('freeradius', 'radiusd'));

$status_database = dalo_radius_check_database(
    isset($dbSocket) ? $dbSocket : null,
    $configValues ?? array()
);

// If the configured database is local, retain a process/service fallback for
// minimal installations where the PHP database driver is temporarily absent.
$local_database_hosts = array('localhost', '127.0.0.1', '::1');
if (!$status_database &&
    !$is_container &&
    in_array($configValues['CONFIG_DB_HOST'] ?? '', $local_database_hosts, true)) {
    $status_database = dalo_radius_check_local_service(
        array('mariadbd', 'mysqld'),
        array('mariadb', 'mysql')
    );
}

$status_sshd = !$is_container && dalo_radius_check_local_service(array('sshd'), array('ssh', 'sshd'));

$label_running = 'running';
$label_stopped = 'not running';
$label_na = 'N/A (Docker container)';

$table = array(
    'title' => (isset($title) && $title !== '') ? $title : 'Service Status',
    'rows' => array()
);

$table['rows'][] = array(
    'FreeRADIUS',
    sprintf(
        '<span class="%s fw-bold">%s</span>',
        $status_freeradius ? 'text-success' : 'text-danger',
        htmlspecialchars($status_freeradius ? $label_running : $label_stopped, ENT_QUOTES, 'UTF-8')
    )
);

$table['rows'][] = array(
    'MySQL / MariaDB',
    sprintf(
        '<span class="%s fw-bold">%s</span>',
        $status_database ? 'text-success' : 'text-danger',
        htmlspecialchars($status_database ? $label_running : $label_stopped, ENT_QUOTES, 'UTF-8')
    )
);

$table['rows'][] = array(
    'SSHd',
    $is_container
        ? sprintf('<span class="text-muted fst-italic">%s</span>', htmlspecialchars($label_na, ENT_QUOTES, 'UTF-8'))
        : sprintf(
            '<span class="%s fw-bold">%s</span>',
            $status_sshd ? 'text-success' : 'text-danger',
            htmlspecialchars($status_sshd ? $label_running : $label_stopped, ENT_QUOTES, 'UTF-8')
        )
);

print_simple_table($table);
