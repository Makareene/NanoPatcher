<?php

include_once 'NanoPatcher.php';

$patcher = new NanoPatcher(__DIR__);
$res = $patcher->run();

print_r($res);

?>