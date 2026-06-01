<?php
/**
 * Scheduled tasks for ainotebook
 *
 * @package    mod_ainotebook
 * @copyright  2026 Tateta (samastanuswantara.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname' => '\mod_ainotebook\task\weekly_teacher_alert',
        'blocking' => 0,
        'minute' => '0',
        'hour' => '0',
        'day' => '*',
        'dayofweek' => '0', // Sunday (0) at midnight
        'month' => '*'
    ],
];
