<?php
/**
 * @package    mod_ainotebook
 * @copyright  2026 Tateta (samastanuswantara.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$cmid = required_param('cmid', PARAM_INT);

$cm = get_coursemodule_from_id('ainotebook', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);

require_login($course, true, $cm);
require_sesskey();

$selected_files = optional_param('selected_files', '[]', PARAM_RAW);
$file_ids = json_decode($selected_files, true) ?: [];

$suggestions = \mod_ainotebook\ai_client::get_suggestions($cmid, $USER->id, $file_ids);

echo json_encode([
    'success' => true,
    'suggestions' => $suggestions
]);
