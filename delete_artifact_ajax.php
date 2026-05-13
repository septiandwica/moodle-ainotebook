<?php
/**
 * AJAX handler to delete saved artifacts from the database.
 */

define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');

$artifactid = required_param('artifactid', PARAM_INT);
$sesskey = required_param('sesskey', PARAM_ALPHANUM);

require_login();
if (!confirm_sesskey($sesskey)) {
    echo json_encode(['success' => false, 'error' => 'Invalid session key']);
    exit;
}

global $DB, $USER;

$artifact = $DB->get_record('ainotebook_artifacts', ['id' => $artifactid, 'userid' => $USER->id], '*', MUST_EXIST);

try {
    $DB->delete_records('ainotebook_artifacts', ['id' => $artifactid]);
    echo json_encode(['success' => true, 'message' => 'Artifact deleted successfully']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
