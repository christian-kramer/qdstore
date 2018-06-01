<?php

error_reporting(E_ALL); ini_set('display_errors', 1);

require "common.php";


$storage = new ShardDrive(shards());
$node = new ShardNode($storage);


if (!$_SERVER['QUERY_STRING'])
{
    $node->identify();
}

if ($_SERVER['QUERY_STRING'] == 'partner')
{
    $node->pick_partner();
}

?>