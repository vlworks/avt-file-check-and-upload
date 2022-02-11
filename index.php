<?php


/**
 * @var array $config
 */
require_once "config.php";
require_once "components.php";

$checkAndUpload = new CheckAndUpload($config);
$checkAndUpload->init();