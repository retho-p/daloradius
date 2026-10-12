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
 * Authors:    Liran Tal <liran@lirantal.com>
 *             Filippo Lauria <filippo.lauria@iit.cnr.it>
 *
 *********************************************************************************************************
 */

// prevent this file to be directly accessed
if (strpos($_SERVER['PHP_SELF'], '/include/menu/sidebar/home/default.php') !== false) {
    header("Location: ../../../../index.php");
    exit;
}


include_once('../common/includes/config_read.php');

// define descriptors
$descriptors1 = array();
$descriptors1[] = array( 'type' => 'link', 'label' => t('button','ServerStatus'), 'href' => 'rep-stat-server.php', 
                         'icon' => 'pc', 'img' => array( 'src' => 'static/images/icons/reportsStatus.png', ), );
$descriptors1[] = array( 'type' => 'link', 'label' => t('button','LastConnectionAttempts'), 'href' => 'rep-lastconnect.php',
                         'icon' => 'clock-history', 'img' => array( 'src' => 'static/images/icons/userList.gif', ), );

$descriptors2 = array();
$descriptors2[] = array( 'type' => 'link', 'label' => t('button','RadiusLog'), 'href' => 'rep-logs-radius.php',
                         'icon' => 'file-earmark-text', );
$descriptors2[] = array( 'type' => 'link', 'label' => t('button','SystemLog'), 'href' => 'rep-logs-system.php',
                         'icon' => 'file-earmark-text', );

$descriptors3 = array();

$content = '<span class="d-block text-start">'
         . '<span class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-1">'
         . '<strong>daloRADIUS</strong>';
if (isset($configValues['DALORADIUS_VERSION'])) {
    $version = htmlspecialchars((string)$configValues['DALORADIUS_VERSION'], ENT_QUOTES, 'UTF-8');
    $content .= '<span class="badge rounded-pill text-bg-primary" aria-label="Version ' . $version . '">v'
              . $version . '</span>';
}
$content .= '</span><span class="d-block text-secondary mb-2">RADIUS Management</span>';
if (!empty($configValues['DALORADIUS_DATE'])) {
    $release_date = (string)$configValues['DALORADIUS_DATE'];
    $parsed_date = DateTimeImmutable::createFromFormat('!j F Y', $release_date);
    $date_errors = DateTimeImmutable::getLastErrors();
    if ($parsed_date !== false && ($date_errors === false ||
        ($date_errors['warning_count'] === 0 && $date_errors['error_count'] === 0))) {
        $date_content = '<time datetime="' . $parsed_date->format('Y-m-d') . '">'
                      . $parsed_date->format('j M Y') . '</time>';
    } else {
        $date_content = htmlspecialchars($release_date, ENT_QUOTES, 'UTF-8');
    }
    $content .= '<span class="d-block small text-secondary mb-2">Released on ' . $date_content . '</span>';
}
$content .= '<a class="d-inline-flex align-items-center gap-1 link-primary link-underline-opacity-0 link-underline-opacity-100-hover"'
          . ' href="https://github.com/lirantal/daloradius/releases" aria-label="View the daloRADIUS changelog">'
          . 'Changelog <i class="bi bi-arrow-right" aria-hidden="true"></i></a></span>';

$descriptors3[] = array(
                            'type' => 'textarea',
                            'content' => $content,
                      );


$sections = array();
$sections[] = array( 'title' => t('sidebar', 'Status'), 'descriptors' => $descriptors1 );
$sections[] = array( 'title' => t('sidebar', 'Logs'), 'descriptors' => $descriptors2 );
$sections[] = array( 'title' => 'About', 'descriptors' => $descriptors3 );

// add sections to menu
$menu = array(
                'title' => t('sidebar', 'Home'),
                'sections' => $sections,
             );
