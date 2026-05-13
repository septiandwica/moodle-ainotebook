<?php
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
purge_all_caches();
echo "PURGE_SUCCESS";
unlink(__FILE__);
