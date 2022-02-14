<?php

require_once __DIR__.'/vendor/autoload.php';

use CheckFilesAndUploadData\GetFiles;
use CheckFilesAndUploadData\GetData;

/**
 * Get Files from local upload folder
 */
$getFiles = new GetFiles('ftp/');
$files = $getFiles->getFiles();
print_r($files);

/**
 * Get data from file
 */
$getData = new GetData();

