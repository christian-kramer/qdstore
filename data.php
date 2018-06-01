<?php

//error_reporting(E_ALL); ini_set('display_errors', 1);

include "node.php";
include "security.php";

$shardnode = new ShardNode;


if (count($_GET))
{
    if ($_GET['query'] && $_GET['namespace'])
    {
        echo $shardnode->read($_GET['query'], $_GET['namespace']);
    }
}

if (count($_POST))
{
    echo $shardnode->store($_POST['json_object'], $_POST['group'], $_POST['namespace'], $_POST['block']);
}


?>