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
$action = optional_param('action', 'chat', PARAM_TEXT);

$cm = get_coursemodule_from_id('ainotebook', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);

require_login($course, true, $cm);
require_sesskey();

if ($action === 'notify_student') {
    $context = context_module::instance($cm->id);
    require_capability('mod/ainotebook:viewprogress', $context);
    
    $target_userid = required_param('userid', PARAM_INT);
    $target_user = $DB->get_record('user', ['id' => $target_userid], '*', MUST_EXIST);
    $ainotebook = $DB->get_record('ainotebook', ['id' => $cm->instance], '*', MUST_EXIST);
    
    $message = new \core\message\message();
    $message->component = 'moodle';
    $message->name = 'instantmessage';
    $message->userfrom = $USER;
    $message->userto = $target_user;
    $message->subject = 'Reminder: Please access DEMI Tutor';
    $message->fullmessage = 'Hello ' . fullname($target_user) . ', please access the DEMI Tutor activity "' . $ainotebook->name . '" in the course "' . $course->fullname . '" to start learning.';
    $message->fullmessageformat = FORMAT_MARKDOWN;
    $message->fullmessagehtml = '<p>Hello ' . fullname($target_user) . ',</p><p>Please access the DEMI Tutor activity <strong>' . $ainotebook->name . '</strong> in the course <strong>' . $course->fullname . '</strong> to start learning.</p>';
    $message->smallmessage = 'Reminder: Please access DEMI Tutor';
    $message->notification = '1';
    $message->contexturl = (new moodle_url('/mod/ainotebook/view.php', ['id' => $cm->id]))->out(false);
    $message->contexturlname = $ainotebook->name;
    $message->courseid = $course->id;
    
    message_send($message);
    
    echo json_encode(['success' => true]);
    exit;
}
if ($action === 'evaluate_student') {
    $context = context_module::instance($cm->id);
    require_capability('mod/ainotebook:viewprogress', $context);
    
    $target_userid = required_param('userid', PARAM_INT);
    $result = \mod_ainotebook\ai_client::evaluate_student($cmid, $target_userid);
    
    echo json_encode([
        'success' => true,
        'evaluation' => $result
    ]);
    exit;
}

if ($action === 'submit_quiz_grade') {
    $score = required_param('score', PARAM_INT);
    $maxscore = required_param('maxscore', PARAM_INT);
    $ainotebook = $DB->get_record('ainotebook', ['id' => $cm->instance], '*', MUST_EXIST);
    
    $attempt = new stdClass();
    $attempt->ainotebookid = $ainotebook->id;
    $attempt->userid = $USER->id;
    $attempt->score = $score;
    $attempt->maxscore = $maxscore;
    $attempt->timecreated = time();
    $DB->insert_record('ainotebook_quiz_attempts', $attempt);
    
    // Trigger grade update
    ainotebook_update_grades($ainotebook, $USER->id, false);
    
    echo json_encode(['success' => true]);
    exit;
}

// Fallback to chat action
$message = required_param('message', PARAM_TEXT);
$selected_files = optional_param('selected_files', '[]', PARAM_RAW);
$file_ids = json_decode($selected_files, true) ?: [];
$config_raw = optional_param('config', '[]', PARAM_RAW);
$config = json_decode($config_raw, true) ?: [];

$response_text = \mod_ainotebook\ai_client::get_response($cmid, $USER->id, $message, $file_ids, $config);

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
