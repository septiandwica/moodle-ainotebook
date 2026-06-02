<?php
/**
 * @package    mod_ainotebook
 * @copyright  2026 Tateta (samastanuswantara.com)
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
        
        $mform->addElement('static', 'files_instruction', '', '<span class="text-info"><i class="fa fa-info-circle"></i> ' . get_string('files_instruction', 'mod_ainotebook') . '</span>');
        
        $filemanageroptions = array();
        $filemanageroptions['accepted_types'] = array('.pdf', '.txt', '.docx', '.pptx');
        $filemanageroptions['maxbytes'] = 0;
        $filemanageroptions['maxfiles'] = 5;
        $filemanageroptions['mainfile'] = true;

        $mform->addElement('filemanager', 'files_filemanager', get_string('files'), null, $filemanageroptions);
        $mform->addHelpButton('files_filemanager', 'files_filemanager', 'mod_ainotebook');

        $this->standard_grading_coursemodule_elements();
        
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
