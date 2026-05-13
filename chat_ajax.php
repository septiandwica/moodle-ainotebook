<?php
/**
 * @package    mod_ainotebook
 * @copyright  2024 Tateta
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$cmid = required_param('cmid', PARAM_INT);
$message = required_param('message', PARAM_TEXT);
$selected_files = optional_param('selected_files', '[]', PARAM_RAW);
$file_ids = json_decode($selected_files, true) ?: [];
$config_raw = optional_param('config', '[]', PARAM_RAW);
$config = json_decode($config_raw, true) ?: [];

$cm = get_coursemodule_from_id('ainotebook', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);

require_login($course, true, $cm);
require_sesskey();

$response_text = \mod_ainotebook\ai_client::get_response($cmid, $USER->id, $message, $file_ids, $config);

// Save to Database (ONLY if NOT silent).
$silent = optional_param('silent', 0, PARAM_INT);

if (!$silent) {
    $log = new stdClass();
    $log->ainotebookid = $cm->instance;
    $log->userid = $USER->id;
    $log->message = $message;
    $log->response = $response_text;
    $log->timecreated = time();
    $DB->insert_record('ainotebook_chat', $log);
}

echo json_encode([
    'success' => true,
    'response' => $response_text
]);
