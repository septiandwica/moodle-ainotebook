<?php
/**
 * @package    mod_ainotebook
 * @copyright  2026 Tateta (samastanuswantara.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Auto-add on course creation is handled via Moodle 4.4 hooks in db/hooks.php
// (hook_callbacks::after_course_form_submission). No event observers required.
$observers = [];
