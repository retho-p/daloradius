<?php
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }
$menu=array('title'=>t('reports_sessions','Title'),'sections'=>array(
    array('title'=>t('reports_sessions','Investigate'),'descriptors'=>array(
        array('type'=>'link','label'=>t('reports_sessions','Title'),'href'=>'rep-sessions.php','icon'=>'list-columns'),
        array('type'=>'link','label'=>t('reports_sessions','Legacy'),'href'=>'rep-online.php','icon'=>'person-lines-fill'),
        array('type'=>'link','label'=>t('button','LastConnectionAttempts'),'href'=>'rep-lastconnect.php','icon'=>'clock-history'),
        array('type'=>'link','label'=>t('button','TopUser'),'href'=>'rep-topusers.php','icon'=>'bar-chart')))));
