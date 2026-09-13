<?php
/**
 * @package    mod_ainotebook
 * @copyright  2026 Tateta (samastanuswantara.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {

    // Automatically update legacy provider in database to 'demi_engine'.
    if (get_config('mod_ainotebook', 'ai_provider') !== 'demi_engine') {
        set_config('ai_provider', 'demi_engine', 'mod_ainotebook');
    }

    // ── AI Branding ───────────────────────────────────────────────────────────
    $settings->add(new admin_setting_configtext(
        'mod_ainotebook/ai_name',
        'AI Name',
        'The display name of your AI Assistant (e.g. DEMI AI Academic Tutor).',
        'DEMI AI Academic Tutor',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configstoredfile(
        'mod_ainotebook/pdf_logo',
        'PDF Export Logo',
        'Upload a custom logo for the PDF export. If empty, the default President University logo will be used.',
        'pdf_logo',
        0,
        ['maxfiles' => 1, 'accepted_types' => ['.png', '.jpg', '.jpeg', '.svg']]
    ));

    // ── Provider Config ───────────────────────────────────────────────────────
    $settings->add(new admin_setting_heading(
        'mod_ainotebook/engine_heading',
        'DEMI Core AI Engine Integration',
        'Centralized AI routing and agent model selection handled via DEMI Core AI Engine.'
    ));

    $settings->add(new admin_setting_configselect(
        'mod_ainotebook/ai_provider',
        'AI Provider',
        'Select AI Provider. DEMI Core AI Engine automatically handles model routing and agent selection.',
        'demi_engine',
        [
            'demi_engine' => 'DEMI Core AI Engine (Central Endpoint - Port 8001)',
        ]
    ));

    // ── DEMI Engine Settings ──────────────────────────────────────────────────
    $settings->add(new admin_setting_configtext(
        'mod_ainotebook/demi_engine_url',
        'DEMI AI Engine Endpoint URL',
        'The base URL of the central DEMI AI Engine FastAPI service.',
        'http://localhost:8001',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'mod_ainotebook/demi_engine_key',
        'DEMI AI Engine Secret Key (X-Engine-API-Key)',
        'Authentication key used to communicate with DEMI Core AI Engine.',
        'demi_secret_engine_key_2026'
    ));

    // ── General Settings ──────────────────────────────────────────────────────
    $settings->add(new admin_setting_heading(
        'mod_ainotebook/general_heading',
        'General Settings',
        ''
    ));

    $settings->add(new admin_setting_configcheckbox(
        'mod_ainotebook/autoadd',
        'Auto-add to new courses',
        'If enabled, the AI Notebook activity will be automatically added to all newly created courses.',
        1
    ));

    // ── Rate Limiting ─────────────────────────────────────────────────────────
    $url = new moodle_url('/mod/ainotebook/usage_report.php');
    $link = \html_writer::link($url, '📊 Open Global Usage Report Dashboard', ['class' => 'btn btn-primary', 'target' => '_blank']);
    
    $settings->add(new admin_setting_heading(
        'mod_ainotebook/ratelimit_heading',
        'Rate Limiting & Usage Tracking',
        'Manage user API quotas and view consumption reports.<br><br>' . $link
    ));

    $settings->add(new admin_setting_configtext(
        'mod_ainotebook/limit_rpm',
        'Requests Per Minute (RPM)',
        'Maximum number of chat requests a student can make in 1 minute. Set to 0 to disable.',
        '10',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'mod_ainotebook/limit_rpd',
        'Requests Per Day (RPD)',
        'Maximum number of chat requests a student can make in 24 hours. Set to 0 to disable.',
        '100',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'mod_ainotebook/limit_tpm',
        'Tokens Per Minute (TPM)',
        'Maximum estimated tokens (input + output) a student can consume in 1 minute. Set to 0 to disable.',
        '4000',
        PARAM_INT
    ));
}