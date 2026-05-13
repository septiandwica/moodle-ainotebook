<?php
require_once(__DIR__ . '/../../config.php');
$logpath = '/tmp/debug_log_ainotebook.txt';
if (file_exists($logpath)) {
    echo "--- LOG CONTENT ---\n";
    echo file_get_contents($logpath);
} else {
    echo "Log file NOT FOUND at: " . $logpath;
}
unlink(__FILE__); // Self-destruct for security.
