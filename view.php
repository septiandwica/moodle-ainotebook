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

// Release session lock immediately so parallel page loads and other course tabs are NEVER blocked
if (class_exists('\core\session\manager')) {
    @\core\session\manager::write_close();
}

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

// Build Syllabus sections & modules with 2-Pass Subsection Merging
$modinfo = get_fast_modinfo($course);
$childSectionMap = [];
$registeredChildNames = [];
$knownSubsections = ['pre activities', 'main activities', 'post activities', 'new subsection'];

$lastParentSecNum = 0;
foreach ($modinfo->get_section_info_all() as $sectionnum => $section) {
    if (!$section->uservisible) continue;
    $raw_name = !empty($section->name) ? trim($section->name) : get_section_name($course, $section);
    $lower_name = strtolower(trim(strip_tags(format_string($raw_name))));

    $is_child = in_array($lower_name, $knownSubsections) || (isset($section->component) && $section->component === 'mod_subsection');

    if ($is_child) {
        if (isset($childSectionMap[$lower_name])) {
            $childSectionMap[$sectionnum] = $childSectionMap[$lower_name];
        } else {
            $childSectionMap[$sectionnum] = $lastParentSecNum;
        }
    } else {
        $lastParentSecNum = $sectionnum;
        if (!empty($modinfo->sections[$sectionnum])) {
            foreach ($modinfo->sections[$sectionnum] as $sec_cmid) {
                $sec_cm = $modinfo->cms[$sec_cmid];
                if ($sec_cm->modname === 'subsection') {
                    $m_name = strtolower(trim($sec_cm->name));
                    if ($m_name) {
                        $childSectionMap[$m_name] = $sectionnum;
                        $registeredChildNames[] = $m_name;
                    }
                }
            }
        }
    }
}

// Pass 1: Build top-level main sections and initialize subsections map
foreach ($modinfo->get_section_info_all() as $sectionnum => $section) {
    if (!$section->uservisible) continue;

    $raw_name = !empty($section->name) ? trim($section->name) : '';
    if (empty($raw_name) && !empty($section->summary)) {
        $clean_summary = trim(strip_tags($section->summary));
        if (!empty($clean_summary)) {
            $lines = explode("\n", $clean_summary);
            $raw_name = trim($lines[0]);
            if (strlen($raw_name) > 80) $raw_name = substr($raw_name, 0, 77) . '...';
        }
    }
    if (empty($raw_name)) $raw_name = get_section_name($course, $section);
    $sectionname = trim(strip_tags(format_string($raw_name)));
    $lower_name = strtolower($sectionname);

    $is_child = isset($childSectionMap[$sectionnum]) || in_array($lower_name, $registeredChildNames) || in_array($lower_name, $knownSubsections);

    if (!$is_child) {
        if (empty($sectionname) || $sectionname === 'New section') {
            $sectionname = ($sectionnum == 0) ? "Course Overview" : "Session " . sprintf("%02d", $sectionnum);
        }

        $subsections_in_sec = [];
        if (!empty($modinfo->sections[$sectionnum])) {
            foreach ($modinfo->sections[$sectionnum] as $sec_cmid) {
                $sec_cm = $modinfo->cms[$sec_cmid];
                if ($sec_cm->uservisible && $sec_cm->modname === 'subsection') {
                    $sub_name = trim($sec_cm->name);
                    $sub_lower = strtolower($sub_name);
                    $subsections_in_sec[$sub_lower] = [
                        'name' => $sub_name,
                        'modules' => [],
                        'modules_count' => 0,
                        'has_modules' => false
                    ];
                }
            }
        }

        $sections_data_map[$sectionnum] = [
            'sectionnum' => $sectionnum,
            'name' => $sectionname,
            'summary' => s(strip_tags($section->summary)),
            'modules' => [],
            'modules_count' => 0,
            'has_modules' => false,
            'subsections_map' => $subsections_in_sec,
        ];
    }
}

// Pass 2: Populate modules into direct section or child subsections
foreach ($modinfo->get_section_info_all() as $sectionnum => $section) {
    if (!$section->uservisible) continue;

    $raw_name = !empty($section->name) ? trim($section->name) : '';
    if (empty($raw_name) && !empty($section->summary)) {
        $clean_summary = trim(strip_tags($section->summary));
        if (!empty($clean_summary)) {
            $lines = explode("\n", $clean_summary);
            $raw_name = trim($lines[0]);
            if (strlen($raw_name) > 80) $raw_name = substr($raw_name, 0, 77) . '...';
        }
    }
    if (empty($raw_name)) $raw_name = get_section_name($course, $section);
    $sectionname = trim(strip_tags(format_string($raw_name)));
    $lower_name = strtolower($sectionname);

    $is_child = isset($childSectionMap[$sectionnum]) || in_array($lower_name, $registeredChildNames) || in_array($lower_name, $knownSubsections);

    if ($is_child) {
        $parentSecNum = $childSectionMap[$sectionnum] ?? ($childSectionMap[$lower_name] ?? null);
        if ($parentSecNum !== null && isset($sections_data_map[$parentSecNum])) {
            if (!isset($sections_data_map[$parentSecNum]['subsections_map'][$lower_name])) {
                $sections_data_map[$parentSecNum]['subsections_map'][$lower_name] = [
                    'name' => $sectionname,
                    'modules' => [],
                    'modules_count' => 0,
                    'has_modules' => false
                ];
            }

            if (!empty($modinfo->sections[$sectionnum])) {
                foreach ($modinfo->sections[$sectionnum] as $sec_cmid) {
                    $sec_cm = $modinfo->cms[$sec_cmid];
                    if (!$sec_cm->uservisible || $sec_cm->modname === 'subsection') continue;

                    $icon = 'fa-file-o';
                    if ($sec_cm->modname === 'resource') $icon = 'fa-file-text-o';
                    elseif ($sec_cm->modname === 'folder') $icon = 'fa-folder-o';
                    elseif ($sec_cm->modname === 'page' || $sec_cm->modname === 'url') $icon = 'fa-globe';
                    elseif ($sec_cm->modname === 'quiz' || $sec_cm->modname === 'assign') $icon = 'fa-pencil-square-o';
                    elseif ($sec_cm->modname === 'ainotebook') $icon = 'fa-graduation-cap';

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

                    $sections_data_map[$parentSecNum]['subsections_map'][$lower_name]['modules'][] = [
                        'cmid' => $sec_cm->id,
                        'name' => s($sec_cm->name),
                        'modname' => $sec_cm->modname,
                        'icon' => $icon,
                        'url' => $url
                    ];
                    $sections_data_map[$parentSecNum]['subsections_map'][$lower_name]['modules_count']++;
                    $sections_data_map[$parentSecNum]['subsections_map'][$lower_name]['has_modules'] = true;
                    $total_modules++;
                }
            }
        }
    } else {
        if (isset($sections_data_map[$sectionnum])) {
            if (!empty($modinfo->sections[$sectionnum])) {
                foreach ($modinfo->sections[$sectionnum] as $sec_cmid) {
                    $sec_cm = $modinfo->cms[$sec_cmid];
                    if (!$sec_cm->uservisible || $sec_cm->modname === 'subsection') continue;

                    $icon = 'fa-file-o';
                    if ($sec_cm->modname === 'resource') $icon = 'fa-file-text-o';
                    elseif ($sec_cm->modname === 'folder') $icon = 'fa-folder-o';
                    elseif ($sec_cm->modname === 'page' || $sec_cm->modname === 'url') $icon = 'fa-globe';
                    elseif ($sec_cm->modname === 'quiz' || $sec_cm->modname === 'assign') $icon = 'fa-pencil-square-o';
                    elseif ($sec_cm->modname === 'ainotebook') $icon = 'fa-graduation-cap';

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

                    $sections_data_map[$sectionnum]['modules'][] = [
                        'cmid' => $sec_cm->id,
                        'name' => s($sec_cm->name),
                        'modname' => $sec_cm->modname,
                        'icon' => $icon,
                        'url' => $url
                    ];
                    $sections_data_map[$sectionnum]['modules_count']++;
                    $sections_data_map[$sectionnum]['has_modules'] = true;
                    $total_modules++;
                }
            }
        }
    }
}

// Convert subsections_map to numerical array for Mustache
$sections_data = [];
foreach ($sections_data_map as $secnum => $secData) {
    $sub_list = array_values($secData['subsections_map']);
    unset($secData['subsections_map']);
    $secData['subsections'] = $sub_list;
    $secData['subsections_count'] = count($sub_list);
    $secData['has_subsections'] = !empty($sub_list);
    $sections_data[] = $secData;
}

$context_data['syllabus_sections'] = $sections_data;
$context_data['files_count'] = count($sections_data);

// Format sessions history array
$sessions = \mod_ainotebook\ai_client::get_sessions($cm->id, $target_user->id);
$active_session_id = '';
$initial_messages = [];

if (!empty($sessions)) {
    $active_session_id = $sessions[0]['session_id'];
    $sessions[0]['is_active'] = true;
    $raw_msgs = \mod_ainotebook\ai_client::get_session_messages($cm->id, $target_user->id, $active_session_id);
    foreach ($raw_msgs as $m) {
        $clean_resp = preg_replace('/```json-quiz[\s\S]*?```/', '', $m['ai_response']);
        $clean_resp = preg_replace('/```mermaid[\s\S]*?```/', '', $clean_resp);
        $clean_resp = preg_replace('/\[REPORT_START\][\s\S]*?\[REPORT_END\]/', '', $clean_resp);
        $clean_resp = trim($clean_resp);
        if (empty($clean_resp)) $clean_resp = "I have generated the requested material below.";
        
        $initial_messages[] = [
            'user_message' => nl2br(s($m['user_message'])),
            'ai_response' => nl2br($clean_resp),
            'raw_user' => $m['user_message'],
            'raw_ai' => $m['ai_response'],
            'time' => $m['time']
        ];
    }
} else {
    // If no local session exists, pull unified history directly from DEMI Core AI Engine
    $unified_logs = \mod_ainotebook\ai_client::get_unified_history($cm->id, $target_user->id);
    if (!empty($unified_logs)) {
        foreach ($unified_logs as $log) {
            $msg = $log->message ?? '';
            $resp = $log->response ?? '';
            if (empty($msg) && empty($resp)) continue;

            $clean_resp = preg_replace('/```json-quiz[\s\S]*?```/', '', $resp);
            $clean_resp = preg_replace('/```mermaid[\s\S]*?```/', '', $clean_resp);
            $clean_resp = preg_replace('/\[REPORT_START\][\s\S]*?\[REPORT_END\]/', '', $clean_resp);
            $clean_resp = trim($clean_resp);
            if (empty($clean_resp)) $clean_resp = "I have generated the requested material below.";

            $time_str = !empty($log->timecreated) ? date('H:i', $log->timecreated) : date('H:i');
            $initial_messages[] = [
                'user_message' => nl2br(s($msg)),
                'ai_response'  => nl2br($clean_resp),
                'raw_user'     => $msg,
                'raw_ai'       => $resp,
                'time'         => $time_str
            ];
        }
    }
}

$context_data['sessions'] = $sessions;
$context_data['sessions_count'] = count($sessions);
$context_data['active_session_id'] = $active_session_id;
$context_data['initial_messages'] = $initial_messages;


$saved_artifacts = $DB->get_records('ainotebook_artifacts', ['ainotebookid' => $ainotebook->id, 'userid' => $target_user->id], 'timecreated DESC');
$context_data['saved_json'] = json_encode(array_values($saved_artifacts));

// Auto-ingest & sync course materials to vector index
try {
    \mod_ainotebook\ai_client::process_all_materials($cm->id);
} catch (\Throwable $e) {
    debugging("mod_ainotebook: Auto process materials failed: " . $e->getMessage(), DEBUG_DEVELOPER);
}

echo $OUTPUT->header();
echo '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">';
echo '<link rel="stylesheet" href="styles.css?v=' . $pluginrev . '">';
echo '<script src="' . $CFG->wwwroot . '/mod/ainotebook/js/marked.min.js?v=' . $pluginrev . '"></script>';
echo '<script src="' . $CFG->wwwroot . '/mod/ainotebook/js/mermaid.min.js?v=' . $pluginrev . '"></script>';

echo $OUTPUT->render_from_template('mod_ainotebook/view', $context_data);
echo $OUTPUT->footer();
