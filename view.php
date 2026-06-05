<?php
/**
 * @package    mod_ainotebook
 * @copyright  2026 Tateta (samastanuswantara.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id = required_param('id', PARAM_INT); // Course Module ID.

$cm = get_coursemodule_from_id('ainotebook', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);
$ainotebook = $DB->get_record('ainotebook', array('id' => $cm->instance), '*', MUST_EXIST);
$config = get_config('mod_ainotebook');
$ai_name = empty($config->ai_name) ? 'DEMI TUTOR' : $config->ai_name;

require_login($course, true, $cm);
if (isguestuser() || !isloggedin()) {
    print_error('noguest');
}
$context = context_module::instance($cm->id);

$viewself = optional_param('viewself', 0, PARAM_INT);
$req_userid = optional_param('userid', 0, PARAM_INT);

$is_teacher = has_capability('mod/ainotebook:viewprogress', $context);
$is_readonly = false;
$target_user = $USER;

if ($is_teacher && $req_userid && $req_userid != $USER->id) {
    $target_user = $DB->get_record('user', ['id' => $req_userid], '*', MUST_EXIST);
    $is_readonly = true;
}

$sesskey = sesskey();
$PAGE->set_url('/mod/ainotebook/view.php', array('id' => $id));
$PAGE->set_title(format_string($ainotebook->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');

echo $OUTPUT->header();

// Include FontAwesome and Custom CSS.
echo '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">';
echo '<link rel="stylesheet" href="styles.css?v=' . time() . '">';

// Include Marked.js and Mermaid.js.
echo '<script src="' . $CFG->wwwroot . '/mod/ainotebook/js/marked.min.js?v=' . time() . '"></script>';
echo '<script src="' . $CFG->wwwroot . '/mod/ainotebook/js/mermaid.min.js?v=' . time() . '"></script>';

$fs = get_file_storage();
$files = $fs->get_area_files($context->id, 'mod_ainotebook', 'files', 0, 'id', false);

$context_data = [
    'cmid' => $cm->id,
    'sesskey' => $sesskey,
    'wwwroot' => $CFG->wwwroot,
    'activityname' => json_encode($ainotebook->name),
    'is_readonly' => $is_readonly,
    'is_teacher' => $is_teacher,
    'is_teacher_self' => ($is_teacher && $viewself),
    'str_readonlymode' => get_string('readonlymode', 'mod_ainotebook'),
    'str_viewingprogressfor' => get_string('viewingprogressfor', 'mod_ainotebook'),
    'str_backtodashboard' => get_string('backtodashboard', 'mod_ainotebook'),
    'str_asksomething' => get_string('asksomething', 'mod_ainotebook'),
    'target_fullname' => fullname($target_user),
    'target_firstname' => $target_user->firstname,
    'target_email' => s($target_user->email),
    'ai_name' => s($ai_name)
];

// PDF Logo URL
$pdf_logo_url = '';
$context_system = context_system::instance();
$logo_files = $fs->get_area_files($context_system->id, 'mod_ainotebook', 'pdf_logo', 0, 'itemid, filepath, filename', false);
if ($logo_files) {
    $logo_file = reset($logo_files);
    $pdf_logo_url = moodle_url::make_pluginfile_url($logo_file->get_contextid(), $logo_file->get_component(), $logo_file->get_filearea(), $logo_file->get_itemid(), $logo_file->get_filepath(), $logo_file->get_filename())->out(false);
} else {
    $pdf_logo_url = $CFG->wwwroot . '/mod/ainotebook/pix/presunivlogo.png';
}
$context_data['pdf_logo_url'] = $pdf_logo_url;

// Format files array
$files_data = [];
foreach ($files as $file) {
    if ($file->is_directory()) continue;
    $files_data[] = [
        'id' => $file->get_id(),
        'filename' => s($file->get_filename()),
        'url' => moodle_url::make_pluginfile_url($file->get_contextid(), $file->get_component(), $file->get_filearea(), $file->get_itemid(), $file->get_filepath(), $file->get_filename())->out()
    ];
}
$context_data['files'] = $files_data;

// Format history array
$history = $DB->get_records('ainotebook_chat', ['ainotebookid' => $ainotebook->id, 'userid' => $target_user->id], 'timecreated ASC');
$hist_array = array_values($history);
$total_hist = count($hist_array);
$history_data = [];

foreach ($hist_array as $index => $log) {
    $clean_response = preg_replace('/```json-quiz[\s\S]*?```/', '', $log->response);
    $clean_response = preg_replace('/```mermaid[\s\S]*?```/', '', $clean_response);
    $clean_response = preg_replace('/\[REPORT_START\][\s\S]*?\[REPORT_END\]/', '', $clean_response);
    
    $sug_match = [];
    $suggestions_html = '';
    if (preg_match('/<suggestions>([\s\S]*?)<\/suggestions>/i', $clean_response, $sug_match)) {
        $clean_response = str_replace($sug_match[0], '', $clean_response);
        if ($index === $total_hist - 1 && !$is_readonly) {
            $suggestions = array_filter(array_map('trim', explode('|', $sug_match[1])));
            if (!empty($suggestions)) {
                $suggestions_html .= '<div class="suggestion-container">';
                foreach ($suggestions as $s) {
                    $s_escaped = s($s);
                    $s_json = htmlspecialchars(json_encode($s));
                    $suggestions_html .= '<button class="suggestion-btn" onclick="sendSuggested('.$s_json.')">' . $s_escaped . '</button>';
                }
                $suggestions_html .= '</div>';
            }
        }
    }
    
    $clean_response = trim($clean_response);
    if (empty($clean_response)) $clean_response = "I have generated the requested material below.";

    $history_data[] = [
        'message' => nl2br(s($log->message)),
        'response' => nl2br($clean_response),
        'suggestions_html' => $suggestions_html
    ];
}
$context_data['history'] = $history_data;

// Format saved artifacts JSON
$saved_artifacts = $DB->get_records('ainotebook_artifacts', ['ainotebookid' => $ainotebook->id, 'userid' => $target_user->id], 'timecreated DESC');
$context_data['saved_json'] = json_encode(array_values($saved_artifacts));

echo $OUTPUT->render_from_template('mod_ainotebook/view', $context_data);

echo $OUTPUT->footer();
