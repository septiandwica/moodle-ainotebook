<?php
/**
 * @package    mod_ainotebook
 * @copyright  2026 Tateta (samastanuswantara.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Adds an instance of ainotebook
 * @param stdClass $ainotebook
 * @param mod_ainotebook_mod_form $mform
 * @return int
 */
function ainotebook_add_instance($ainotebook, $mform) {
    global $DB;

    $ainotebook->timecreated = time();
    $ainotebook->timemodified = $ainotebook->timecreated;

    $id = $DB->insert_record('ainotebook', $ainotebook);
    $ainotebook->id = $id;

    if ($mform) {
        $context = context_module::instance($ainotebook->coursemodule);
        file_postupdate_standard_filemanager($ainotebook, 'files', array('subdirs' => 0, 'maxfiles' => 5), $context, 'mod_ainotebook', 'files', 0);
    }

    return $id;
}

/**
 * Updates an instance of ainotebook
 * @param stdClass $ainotebook
 * @param mod_ainotebook_mod_form $mform
 * @return bool
 */
function ainotebook_update_instance($ainotebook, $mform) {
    global $DB;

    $ainotebook->timemodified = time();
    $ainotebook->id = $ainotebook->instance;

    $DB->update_record('ainotebook', $ainotebook);

    if ($mform) {
        file_postupdate_standard_filemanager($ainotebook, 'files', array('subdirs' => 0, 'maxfiles' => 5), $mform->get_context(), 'mod_ainotebook', 'files', 0);
    }

    return true;
}

/**
 * Deletes an instance of ainotebook
 * @param int $id
 * @return bool
 */
function ainotebook_delete_instance($id) {
    global $DB;

    if (!$ainotebook = $DB->get_record('ainotebook', array('id' => $id))) {
        return false;
    }

    $DB->delete_records('ainotebook_chat', array('ainotebookid' => $ainotebook->id));
    $DB->delete_records('ainotebook', array('id' => $ainotebook->id));

    return true;
}

/**
 * Supports features
 * @param string $feature
 * @return mixed
 */
function ainotebook_supports($feature) {
    switch($feature) {
        case FEATURE_MOD_INTRO:         return true;
        case FEATURE_SHOW_DESCRIPTION:  return true;
        case FEATURE_GRADE_HAS_GRADE:   return true;
        case FEATURE_GRADE_OUTCOMES:    return true;
        case FEATURE_COMPLETION_TRACKS_VIEWS: return true;
        default: return null;
    }
}

/**
 * Create or update grade item for given ainotebook instance.
 *
 * @param stdClass $ainotebook
 * @param mixed $grades optional array/object of grade(s)
 * @return int 0 if ok, error code otherwise
 */
function ainotebook_grade_item_update($ainotebook, $grades=null, $itemnumber=0) {
    global $CFG;
    if (!function_exists('grade_update')) {
        require_once($CFG->libdir.'/gradelib.php');
    }

    $params = array('itemname' => $ainotebook->name, 'idnumber' => $ainotebook->cmidnumber);

    if ($itemnumber == 1) {
        $params['itemname'] = $ainotebook->name . ' - ' . get_string('quizgrade', 'mod_ainotebook');
        $params['gradetype'] = GRADE_TYPE_VALUE;
        $params['grademax']  = 100;
        $params['grademin']  = 0;
    } else {
        if (isset($ainotebook->grade) && $ainotebook->grade > 0) {
            $params['gradetype'] = GRADE_TYPE_VALUE;
            $params['grademax']  = $ainotebook->grade;
            $params['grademin']  = 0;
        } else if (isset($ainotebook->grade) && $ainotebook->grade < 0) {
            $params['gradetype'] = GRADE_TYPE_SCALE;
            $params['scaleid']   = -$ainotebook->grade;
        } else {
            $params['gradetype'] = GRADE_TYPE_TEXT;
        }
    }

    if ($grades === 'reset') {
        $params['reset'] = true;
        $grades = null;
    }

    return grade_update('mod/ainotebook', $ainotebook->course, 'mod', 'ainotebook', $ainotebook->id, $itemnumber, $grades, $params);
}

/**
 * Update grades in central gradebook
 *
 * @param stdClass $ainotebook
 * @param int $userid
 * @param bool $nullifnone
 */
function ainotebook_update_grades($ainotebook, $userid = 0, $nullifnone = true) {
    global $CFG, $DB;
    require_once($CFG->libdir.'/gradelib.php');

    // Update item 0 (Evaluation)
    if ($ainotebook->grade == 0) {
        ainotebook_grade_item_update($ainotebook, null, 0);
    } else if ($grades = ainotebook_get_user_grades($ainotebook, $userid)) {
        ainotebook_grade_item_update($ainotebook, $grades, 0);
    } else if ($userid && ($nullifnone)) {
        $grade = new stdClass();
        $grade->userid   = $userid;
        $grade->rawgrade = null;
        ainotebook_grade_item_update($ainotebook, $grade, 0);
    } else {
        ainotebook_grade_item_update($ainotebook, null, 0);
    }

    // Update item 1 (Quiz)
    if ($ainotebook->grade != 0) {
        if ($grades1 = ainotebook_get_user_quiz_grades($ainotebook, $userid)) {
            ainotebook_grade_item_update($ainotebook, $grades1, 1);
        } else if ($userid && ($nullifnone)) {
            $grade = new stdClass();
            $grade->userid   = $userid;
            $grade->rawgrade = null;
            ainotebook_grade_item_update($ainotebook, $grade, 1);
        } else {
            ainotebook_grade_item_update($ainotebook, null, 1);
        }
    }
}

/**
 * Get the grades for a specific user.
 *
 * @param stdClass $ainotebook
 * @param int $userid
 * @return array
 */
function ainotebook_get_user_grades($ainotebook, $userid = 0) {
    global $DB;
    
    $params = array('ainotebookid' => $ainotebook->id);
    if ($userid) {
        $params['userid'] = $userid;
    }
    
    $evals = $DB->get_records('ainotebook_evals', $params);
    
    $grades = array();
    foreach ($evals as $eval) {
        $grades[$eval->userid] = new stdClass();
        $grades[$eval->userid]->userid = $eval->userid;
        $grades[$eval->userid]->rawgrade = $eval->score;
        $grades[$eval->userid]->datesubmitted = $eval->timecreated;
        $grades[$eval->userid]->dategraded = $eval->timemodified;
    }
    return $grades;
}

/**
 * Get the quiz grades for a specific user.
 *
 * @param stdClass $ainotebook
 * @param int $userid
 * @return array
 */
function ainotebook_get_user_quiz_grades($ainotebook, $userid = 0) {
    global $DB;
    
    $params = array('ainotebookid' => $ainotebook->id);
    if ($userid) {
        $params['userid'] = $userid;
    }
    
    // Get highest quiz score per user
    $sql = "SELECT userid, MAX( (score * 1.0 / maxscore) * 100 ) AS max_percent, MAX(timecreated) AS latest_time
              FROM {ainotebook_quiz_attempts}
             WHERE ainotebookid = :ainotebookid";
             
    if ($userid) {
        $sql .= " AND userid = :userid";
    }
    $sql .= " GROUP BY userid";
    
    $attempts = $DB->get_records_sql($sql, $params);
    
    $grades = array();
    foreach ($attempts as $attempt) {
        $grades[$attempt->userid] = new stdClass();
        $grades[$attempt->userid]->userid = $attempt->userid;
        $grades[$attempt->userid]->rawgrade = round($attempt->max_percent);
        $grades[$attempt->userid]->datesubmitted = $attempt->latest_time;
        $grades[$attempt->userid]->dategraded = $attempt->latest_time;
    }
    return $grades;
}
/**
 * Serves the ainotebook files
 *
 * @param stdClass $course course object
 * @param stdClass $cm course module object
 * @param stdClass $context context object
 * @param string $filearea file area
 * @param array $args extra arguments
 * @param bool $forcedownload whether or not force download
 * @param array $options additional options for serving the file
 * @return bool false if file not found, does not return if found - just send the file
 */
function ainotebook_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = array()) {
    global $DB;

    if ($context->contextlevel != CONTEXT_MODULE) {
        return false;
    }

    require_login($course, false, $cm);

    if ($filearea !== 'files') {
        return false;
    }

    // The first argument is the itemid (which is 0 for us).
    $itemid = (int)array_shift($args);
    if ($itemid !== 0) {
        return false;
    }

    // The rest is the path and filename.
    $filename = array_pop($args);
    $filepath = '/' . implode('/', $args) . '/';

    $fs = get_file_storage();
    if (!$file = $fs->get_file($context->id, 'mod_ainotebook', 'files', $itemid, $filepath, $filename) or $file->is_directory()) {
        return false;
    }

    send_stored_file($file, 0, 0, $forcedownload, $options);
}

/**
 * Extends the settings navigation for ainotebook to add custom tabs.
 *
 * @param settings_navigation $settingsnav
 * @param navigation_node $ainotebooknode
 */
function ainotebook_extend_settings_navigation(settings_navigation $settingsnav, navigation_node $ainotebooknode = null) {
    global $PAGE;

    if (!isset($PAGE->cm->id)) {
        return;
    }

    $context = context_module::instance($PAGE->cm->id);

    if (has_capability('mod/ainotebook:viewprogress', $context)) {
        $url = new moodle_url('/mod/ainotebook/report.php', array('id' => $PAGE->cm->id));
        $node = navigation_node::create(
            'Student Results',
            $url,
            navigation_node::NODETYPE_LEAF,
            'mod_ainotebook_report',
            'mod_ainotebook_report',
            new pix_icon('i/report', '')
        );

        if ($ainotebooknode) {
            $ainotebooknode->add_node($node);
        }
    }
}
