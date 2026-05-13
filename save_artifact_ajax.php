<?php
/**
 * AJAX handler to save generated artifacts (Quiz, Mindmap, Report) to the database.
 */

define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');

$cmid = required_param('cmid', PARAM_INT);
$sesskey = required_param('sesskey', PARAM_ALPHANUM);
$type = required_param('type', PARAM_TEXT);
$title = required_param('title', PARAM_TEXT);
$content = required_param('content', PARAM_RAW);

require_login();
if (!confirm_sesskey($sesskey)) {
    echo json_encode(['success' => false, 'error' => 'Invalid session key']);
    exit;
}

$cm = get_coursemodule_from_id('ainotebook', $cmid, 0, false, MUST_EXIST);
$context = context_module::instance($cm->id);
require_capability('mod/ainotebook:view', $context);

global $DB;

$record = new stdClass();
$record->ainotebookid = $cm->instance;
$record->userid = $USER->id;
$record->type = $type;
$record->title = $title;
$record->content = $content;
$record->timecreated = time();

try {
    $id = $DB->insert_record('ainotebook_artifacts', $record);
    echo json_encode(['success' => true, 'id' => $id, 'message' => 'Artifact saved successfully']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
