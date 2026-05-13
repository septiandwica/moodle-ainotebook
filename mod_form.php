<?php
/**
 * @package    mod_ainotebook
 * @copyright  2024 Tateta
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot.'/course/moodleform_mod.php');

class mod_ainotebook_mod_form extends moodleform_mod {

    function definition() {
        $mform = $this->_form;

        $mform->addElement('header', 'general', get_string('general', 'form'));

        $mform->addElement('text', 'name', get_string('name'), array('size'=>'64'));
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', get_string('required'), 'required', null, 'client');

        $this->standard_intro_elements();

        $mform->addElement('header', 'materialhdr', get_string('material', 'mod_ainotebook'));
        
        $filemanageroptions = array();
        $filemanageroptions['accepted_types'] = array('.pdf', '.txt', '.doc', '.docx');
        $filemanageroptions['maxbytes'] = 0;
        $filemanageroptions['maxfiles'] = 5;
        $filemanageroptions['mainfile'] = true;

        $mform->addElement('filemanager', 'files_filemanager', get_string('files'), null, $filemanageroptions);

        $this->standard_coursemodule_elements();

        $this->add_action_buttons();
    }

    function data_preprocessing(&$default_values) {
        if ($this->current->instance) {
            $data = (object)$default_values;
            file_prepare_standard_filemanager($data, 'files', array('subdirs' => 0, 'maxfiles' => 5), $this->context, 'mod_ainotebook', 'files', 0);
            $default_values = (array)$data;
        }
    }
}
