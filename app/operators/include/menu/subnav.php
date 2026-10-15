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
 * Authors:    Filippo Lauria <filippo.lauria@iit.cnr.it>
 *
 *********************************************************************************************************
 */

// prevent this file to be directly accessed
if (strpos($_SERVER['PHP_SELF'], '/include/menu/subnav.php') !== false) {
    header("Location: ../../index.php");
    exit;
}

// load subnav category. valid categories are:
// "acct", "bill", "gis", "help", "mng", "rep", "config", "graphs", "home"

$subnav = array();

// home subnav elements
$subnav["home"] = array();

// graphs subnav elements
$subnav["graphs"] = array();

// config subnav elements
// Categories now live in the collapsible sidebar.
$subnav["config"] = array();

// Reports categories now live in the collapsible sidebar.
$subnav["rep"] = array();

// mng subnav elements
// Management categories now live in the collapsible sidebar.
$subnav["mng"] = array();

// help subnav elements
$subnav["help"] = array();

// gis subnav elements
$subnav["gis"] = array();

// bill subnav elements
// Categories now live in the collapsible sidebar.
$subnav["bill"] = array();

// acct subnav elements
// Categories now live in the collapsible sidebar.
$subnav["acct"] = array();




// detect category from the PHP_SELF name
$basename = basename($_SERVER['PHP_SELF']);
$detect_category = substr($basename, 0, strpos($basename, '-'));
if (!in_array($detect_category, array_keys($subnav))) {
    $detect_category = "home";
}

if (!empty($detect_category) && count($subnav[$detect_category]) > 0) {

?>

<nav class="app-subnav border-bottom text-bg-light py-1">
    <div class="container-fluid px-2 px-lg-3">
        <ul class="nav flex-nowrap overflow-auto">
<?php
            foreach ($subnav[$detect_category] as $label => $href) {
                $label = htmlspecialchars(strip_tags(trim(t('submenu', $label))), ENT_QUOTES, 'UTF-8');
                printf('<li><a class="nav-link link-dark px-2" href="%s">%s</a></li>', urlencode($href), $label);
            }
?>
        </ul>
    </div>
</nav>

<?php

}
