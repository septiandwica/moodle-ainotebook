<?php
/**
 * @package    mod_ainotebook
 * @copyright  2024 Tateta
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_ainotebook;

defined('MOODLE_INTERNAL') || die();

class hook_callbacks {

    /**
     * Callback for \core_course\hook\after_form_definition
     *
     * @param \core_course\hook\after_form_definition $hook
     */
    public static function after_course_form_definition(\core_course\hook\after_form_definition $hook): void {
        $mform = $hook->mform;

        // Add the AI Notebook section above course format.
        $mform->insertElementBefore(
            $mform->createElement('header', 'ainotebookhdr', get_string('pluginname', 'mod_ainotebook')),
            'courseformathdr'
        );

        $mform->insertElementBefore(
            $mform->createElement('selectyesno', 'ainotebook_enable', get_string('settings_course_enable', 'mod_ainotebook')),
            'courseformathdr'
        );

        $mform->insertElementBefore(
            $mform->createElement('static', 'ainotebook_desc', '', get_string('settings_course_desc', 'mod_ainotebook')),
            'courseformathdr'
        );

        $mform->setDefault('ainotebook_enable', 1);
    }

    /**
     * Callback for \core_course\hook\after_form_submission
     *
     * @param \core_course\hook\after_form_submission $hook
     */
    public static function after_course_form_submission(\core_course\hook\after_form_submission $hook): void {
        global $DB;

        $data = $hook->get_data();
        
        // If the toggle is not set or disabled in the form, do nothing.
        if (empty($data->ainotebook_enable)) {
            return;
        }

        // Also check global setting.
        $autoadd = get_config('mod_ainotebook', 'autoadd');
        if ($autoadd === false) {
            $autoadd = 1; // Default to true.
        }
        
        if (!$autoadd) {
            return;
        }

        $courseid = $data->id;

        // Check if an AI Notebook already exists in this course.
        $exists = $DB->record_exists('ainotebook', ['course' => $courseid]);
        if (!$exists) {
            self::add_default_instance($courseid);
        }
    }

    /**
     * Adds a default AI Notebook instance to a course.
     *
     * @param int $courseid
     */
    protected static function add_default_instance(int $courseid): void {
        global $DB;

        require_once(__DIR__ . '/../lib.php');
        require_once(dirname(dirname(dirname(__DIR__))) . '/course/lib.php');

        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        $module = $DB->get_record('modules', ['name' => 'ainotebook'], '*', MUST_EXIST);

        // Create the instance record.
        $ainotebook = new \stdClass();
        $ainotebook->course = $courseid;
        $ainotebook->name = get_string('pluginname', 'mod_ainotebook');
        $ainotebook->intro = get_string('modulename_help', 'mod_ainotebook');
        $ainotebook->introformat = FORMAT_HTML;
        $ainotebook->timecreated = time();
        $ainotebook->timemodified = $ainotebook->timecreated;

        $instanceid = $DB->insert_record('ainotebook', $ainotebook);

        // Add the course module.
        $cw = $DB->get_record('course_sections', ['course' => $courseid, 'section' => 0]);
        if (!$cw) {
            $cw = new \stdClass();
            $cw->course = $courseid;
            $cw->section = 0;
            $cw->summary = '';
            $cw->summaryformat = FORMAT_HTML;
            $cw->id = $DB->insert_record('course_sections', $cw);
        }

        $cm = new \stdClass();
        $cm->course = $courseid;
        $cm->module = $module->id;
        $cm->instance = $instanceid;
        $cm->section = $cw->id;
        $cm->added = time();
        $cm->id = $DB->insert_record('course_modules', $cm);

        // Update the section.
        if (empty($cw->sequence)) {
            $cw->sequence = $cm->id;
        } else {
            $cw->sequence .= ',' . $cm->id;
        }
        $DB->update_record('course_sections', $cw);

        // Rebuild the course cache.
        rebuild_course_cache($courseid, true);
    }
}
