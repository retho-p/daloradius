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
if (strpos($_SERVER['PHP_SELF'], '/include/menu/sidebar/mng/default.php') !== false) {
    header("Location: ../../../../index.php");
    exit;
}

include_once("include/management/populate_selectbox.php");
$menu_users = get_users('CONFIG_DB_TBL_RADCHECK');

$descriptors = array();
$descriptors[] = array( 'type' => 'link', 'label' => t('button', 'ListUsers'),
                        'href' => 'mng-list-all.php', 'icon' => 'person-lines-fill', );
if (count($menu_users) > 0) {
    $descriptors[] = array( 'type' => 'link', 'label' => t('button', 'RemoveUsers'),
                            'href' => 'mng-del.php', 'icon' => 'person-fill-x', );
}

$menu = array(
    'title' => t('sidebar', 'Management'),
    'sections' => array(
        array( 'title' => t('sidebar', 'UsersManagement'),
               'class' => 'sidebar-users-menu',
               'descriptors' => $descriptors, ),
    ),
);
