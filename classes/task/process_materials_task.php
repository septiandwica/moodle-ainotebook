<?php
/**
 * @package    mod_ainotebook
 * @copyright  2026 Tateta (samastanuswantara.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_ainotebook\task;

defined('MOODLE_INTERNAL') || die();

class process_materials_task extends \core\task\adhoc_task {
    public function get_name() {
        return get_string('process_materials_task', 'mod_ainotebook');
    }

    public function execute() {
        global $DB;
        $customdata = $this->get_custom_data();
        if (!isset($customdata->ainotebookid)) {
            return;
        }

        $ainotebookid = $customdata->ainotebookid;
        $cm = get_coursemodule_from_instance('ainotebook', $ainotebookid);
        if (!$cm) return;

        // Process files
        \mod_ainotebook\ai_client::process_all_materials($cm->id);
    }
}
