<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../moodle/config.php');
require_once(__DIR__ . '/classes/ai_client.php');

$file_ids = [1]; // dummy
try {
    $chunks = \ai_client::search_knowledge(1, $file_ids, "test", 5);
    echo "Success: " . count($chunks) . " chunks found\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
