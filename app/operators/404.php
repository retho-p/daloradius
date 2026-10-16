<?php
/*
 * daloRADIUS - RADIUS Web Platform
 * Copyright (C) 2007 - Liran Tal <liran@lirantal.com> All Rights Reserved.
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

// Standalone by design: missing pages must not require a login or database connection.
http_response_code(404);
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

// SCRIPT_NAME points to this handler during Apache's internal error redirect.
// Root asset URLs also work when the missing URL is several directories deep.
$script = (string) ($_SERVER['SCRIPT_NAME'] ?? '/404.php');
$position = strpos($script, '/404.php');
$base = $position !== false ? substr($script, 0, $position) . '/' : '/';
if (substr($base, 0, 1) !== '/' || substr($base, 0, 2) === '//'
    || strpos($base, "\\") !== false || preg_match('/[\x00-\x20\x7f]/', $base)) {
    $base = '/';
}
$base = htmlspecialchars($base, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Page not found :: daloRADIUS</title>
    <link rel="icon" type="image/png" href="<?= $base ?>static/images/favicon/favicon-32x32.png">
    <script src="<?= $base ?>static/js/operator-theme.js?v=1"></script>
    <link rel="stylesheet" href="<?= $base ?>static/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= $base ?>static/css/operator-theme.css?v=2">
    <link rel="stylesheet" href="<?= $base ?>static/css/operator-error.css?v=1">
</head>
<body class="operator-app operator-error">
    <header class="error-header border-bottom">
        <a class="app-brand" href="<?= $base ?>index.php" aria-label="daloRADIUS home">
            <img src="<?= $base ?>static/images/daloradius_small.png" alt="daloRADIUS" width="135" height="41">
        </a>
        <span class="text-body-secondary small">Operator portal</span>
    </header>
    <main class="error-main">
        <section class="card error-card" aria-labelledby="error-title">
            <div class="error-status" aria-hidden="true">
                <span class="error-code">404</span>
                <span class="error-status-label">PAGE NOT FOUND</span>
            </div>
            <div class="error-copy">
                <p class="error-eyebrow">HTTP 404</p>
                <h1 id="error-title">Page not found</h1>
                <p class="text-body-secondary">The page you are looking for may have been moved, removed, or the address may be incorrect.</p>
                <p class="text-body-secondary">Check the address or continue from one of the pages below.</p>
                <div class="error-actions">
                    <a class="btn btn-primary" href="<?= $base ?>index.php">Back to home</a>
                </div>
            </div>
        </section>
    </main>
    <footer class="error-footer text-body-secondary small">daloRADIUS · RADIUS management</footer>
</body>
</html>
