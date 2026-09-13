<?php
/**
 * @package    mod_ainotebook
 * @copyright  2026 Tateta (samastanuswantara.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id = optional_param('id', 0, PARAM_INT); // Course Module ID.
$n  = optional_param('n', 0, PARAM_INT);  // Activity Instance ID.

$target_id = $id ?: $n;
if (!$target_id) {
    throw new \moodle_exception('invalidcoursemodule', 'error');
}

$cm = \mod_ainotebook\ai_client::get_cm_safe($target_id);
$course = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);
$ainotebook = $DB->get_record('ainotebook', array('id' => $cm->instance), '*', MUST_EXIST);
$config = get_config('mod_ainotebook');
$ai_name = empty($config->ai_name) ? 'DEMI AI Academic Tutor' : $config->ai_name;

require_login($course, true, $cm);
if (isguestuser() || !isloggedin()) {
    throw new \moodle_exception('noguest');
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

// Get files.
$fs = get_file_storage();
$files = $fs->get_area_files($context->id, 'mod_ainotebook', 'files', 0, 'id', false);

$plugin = new stdClass();
require_once(__DIR__ . '/version.php');
$pluginrev = $plugin->version;

$context_data = [
    'cmid' => $cm->id,
    'courseid' => $course->id,
    'course_fullname' => s($course->fullname),
    'activity_name' => s($ainotebook->name),
    'activityname' => json_encode($ainotebook->name),
    'is_readonly' => $is_readonly,
    'is_teacher' => $is_teacher,
    'is_teacher_self' => ($is_teacher && $viewself),
    'sesskey' => $sesskey,
    'wwwroot' => $CFG->wwwroot,
    'str_readonlymode' => get_string('readonlymode', 'mod_ainotebook'),
    'str_viewingprogressfor' => get_string('viewingprogressfor', 'mod_ainotebook'),
    'str_backtodashboard' => get_string('backtodashboard', 'mod_ainotebook'),
    'str_asksomething' => get_string('asksomething', 'mod_ainotebook'),
    'target_fullname' => fullname($target_user),
    'target_firstname' => $target_user->firstname,
    'target_email' => s($target_user->email),
    'target_idnumber' => addslashes($target_user->idnumber ?: $target_user->id),
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

// Build Syllabus sections & modules (Matching AiTutor.tsx / Portal syllabus tree)
$modinfo = get_fast_modinfo($course);
$sections_data = [];
$total_modules = 0;

foreach ($modinfo->get_section_info_all() as $sectionnum => $section) {
    if (!$section->uservisible) {
        continue;
    }
    
    // Skip empty section 0 if unnamed and no items
    if ($sectionnum == 0 && empty($section->summary) && empty($section->name) && empty($modinfo->sections[0])) {
        continue;
    }

    $sectionname = get_section_name($course, $section);
    if (empty(trim($sectionname))) {
        $sectionname = "Topic " . $sectionnum;
    }

    $modules = [];
    if (!empty($modinfo->sections[$sectionnum])) {
        foreach ($modinfo->sections[$sectionnum] as $sec_cmid) {
            $sec_cm = $modinfo->cms[$sec_cmid];
            if (!$sec_cm->uservisible) continue;

            $icon = 'fa-file-o';
            if ($sec_cm->modname === 'resource') {
                $icon = 'fa-file-text-o';
            } elseif ($sec_cm->modname === 'folder') {
                $icon = 'fa-folder-o';
            } elseif ($sec_cm->modname === 'page' || $sec_cm->modname === 'url') {
                $icon = 'fa-globe';
            } elseif ($sec_cm->modname === 'quiz' || $sec_cm->modname === 'assign') {
                $icon = 'fa-pencil-square-o';
            } elseif ($sec_cm->modname === 'ainotebook') {
                $icon = 'fa-graduation-cap';
            }

            $url = $sec_cm->url ? $sec_cm->url->out() : '#';
            if ($sec_cm->modname === 'resource' || $sec_cm->modname === 'folder') {
                $c_ctx = context_module::instance($sec_cm->id, IGNORE_MISSING);
                if ($c_ctx) {
                    $area_files = $fs->get_area_files($c_ctx->id, 'mod_' . $sec_cm->modname, 'content', 0, 'id ASC', false);
                    foreach ($area_files as $f) {
                        if (!$f->is_directory() && $f->get_filesize() > 0) {
                            $f_ext = strtolower(pathinfo($f->get_filename(), PATHINFO_EXTENSION));
                            if ($f_ext === 'pdf') $icon = 'fa-file-pdf-o';
                            elseif (in_array($f_ext, ['pptx', 'ppt'])) $icon = 'fa-file-powerpoint-o';
                            elseif (in_array($f_ext, ['docx', 'doc'])) $icon = 'fa-file-word-o';
                            $url = moodle_url::make_pluginfile_url($f->get_contextid(), $f->get_component(), $f->get_filearea(), $f->get_itemid(), $f->get_filepath(), $f->get_filename())->out();
                            break;
                        }
                    }
                }
            }

            $modules[] = [
                'cmid' => $sec_cm->id,
                'name' => s($sec_cm->name),
                'modname' => $sec_cm->modname,
                'icon' => $icon,
                'url' => $url
            ];
            $total_modules++;
        }
    }

    $sections_data[] = [
        'section_id' => $section->id,
        'section_num' => $sectionnum,
        'name' => s($sectionname),
        'modules' => $modules,
        'modules_count' => count($modules)
    ];
}

$context_data['syllabus_sections'] = $sections_data;
$context_data['files_count'] = count($sections_data);

// Format history array
$history = \mod_ainotebook\ai_client::get_unified_history($cm->id, $target_user->id);
$history_data = [];

foreach ($history as $index => $log) {
    $clean_response = preg_replace('/```json-quiz[\s\S]*?```/', '', $log->response);
    $clean_response = preg_replace('/```mermaid[\s\S]*?```/', '', $clean_response);
    $clean_response = preg_replace('/\[REPORT_START\][\s\S]*?\[REPORT_END\]/', '', $clean_response);
    $clean_response = trim($clean_response);
    if (empty($clean_response)) $clean_response = "I have generated the requested material below.";
    $time_str = !empty($log->timecreated) ? date('h:i A', $log->timecreated) : date('h:i A');

    $diff = time() - (!empty($log->timecreated) ? $log->timecreated : time());
    if ($diff < 60) {
        $rel_time = 'Just now';
    } elseif ($diff < 3600) {
        $mins = max(1, floor($diff / 60));
        $rel_time = $mins . ($mins == 1 ? ' minute ago' : ' minutes ago');
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        $rel_time = $hours . ($hours == 1 ? ' hour ago' : ' hours ago');
    } else {
        $days = floor($diff / 86400);
        $rel_time = $days . ($days == 1 ? ' day ago' : ' days ago');
    }

    $snippet = !empty($log->message) ? s($log->message) : 'Discussion session';

    $history_data[] = [
        'message' => nl2br(s($log->message)),
        'response' => nl2br($clean_response),
        'time' => $time_str,
        'rel_time' => $rel_time,
        'snippet' => $snippet
    ];
}
$context_data['history'] = $history_data;
$context_data['history_count'] = count($history_data);

$saved_artifacts = $DB->get_records('ainotebook_artifacts', ['ainotebookid' => $ainotebook->id, 'userid' => $target_user->id], 'timecreated DESC');
$context_data['saved_json'] = json_encode(array_values($saved_artifacts));

echo $OUTPUT->header();
echo '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">';
echo '<link rel="stylesheet" href="styles.css?v=' . $pluginrev . '">';
echo '<script src="' . $CFG->wwwroot . '/mod/ainotebook/js/marked.min.js?v=' . $pluginrev . '"></script>';
echo '<script src="' . $CFG->wwwroot . '/mod/ainotebook/js/mermaid.min.js?v=' . $pluginrev . '"></script>';

echo $OUTPUT->render_from_template('mod_ainotebook/view', $context_data);
echo $OUTPUT->footer();
