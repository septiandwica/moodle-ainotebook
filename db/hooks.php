<?php
/**
 * @package    mod_ainotebook
 * @copyright  2026 Tateta (samastanuswantara.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$callbacks = [
    [
        'hook' => \core_course\hook\after_form_definition::class,
        'callback' => \mod_ainotebook\hook_callbacks::class . '::after_course_form_definition',
        'priority' => 100,
    ],
    [
        'hook' => \core_course\hook\after_form_submission::class,
        'callback' => \mod_ainotebook\hook_callbacks::class . '::after_course_form_submission',
        'priority' => 100,
    ],
];
