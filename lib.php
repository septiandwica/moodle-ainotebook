<?php
/**
 * @package    mod_ainotebook
 * @copyright  2024 Tateta
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
        file_postupdate_standard_filemanager($ainotebook, 'files', array('subdirs' => 0, 'maxfiles' => 5), $mform->get_context(), 'mod_ainotebook', 'files', 0);
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
        case FEATURE_GRADE_HAS_GRADE:   return false;
        case FEATURE_COMPLETION_TRACKS_VIEWS: return true;
        default: return null;
    }
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
