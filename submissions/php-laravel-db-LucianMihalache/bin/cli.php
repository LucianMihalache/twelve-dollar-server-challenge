<?php
// FrankenPHP's `php-cli` (on PHP 8.5) hands a script "frankenphp" as $argv[0] and the script itself as $argv[1].
// Composer and artisan expect the script at $argv[0], as the php command does. This shifts the list and runs it.
array_shift($_SERVER['argv']);                   // "frankenphp"
array_shift($_SERVER['argv']);                   // this file
$_SERVER['argc'] = count($_SERVER['argv']);
$argv = $GLOBALS['argv'] = $_SERVER['argv'];
$argc = $GLOBALS['argc'] = $_SERVER['argc'];
$_SERVER['SCRIPT_FILENAME'] = $_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = $argv[0];
require $argv[0];
