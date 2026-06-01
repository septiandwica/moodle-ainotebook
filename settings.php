<?php
/**
 * @package    mod_ainotebook
 * @copyright  2026 Tateta (samastanuswantara.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {

    // ── AI Branding ───────────────────────────────────────────────────────────
    $settings->add(new admin_setting_configtext(
        'mod_ainotebook/ai_name',
        'AI Name',
        'The display name of your AI Assistant (e.g. PresMate).',
        'PresMate',
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

    // ── Provider selector ─────────────────────────────────────────────────────
    $settings->add(new admin_setting_configselect(
        'mod_ainotebook/ai_provider',
        'AI Provider',
        'Select which AI provider to use.',
        'groq',
        [
            'groq'   => 'Groq (Fastest)',
            'openai' => 'OpenAI',
            'gemini' => 'Google Gemini',
            'moodle' => 'Moodle AI Subsystem (Default)',
        ]
    ));

    // ── API Key ───────────────────────────────────────────────────────────────
    $settings->add(new admin_setting_configpasswordunmask(
        'mod_ainotebook/api_key',
        'API Key',
        'Your API Key for the selected provider (leave empty if using Moodle AI).',
        ''
    ));

    // ── Groq Models (updated May 2025) ────────────────────────────────────────
    $settings->add(new admin_setting_configselect(
        'mod_ainotebook/model_groq',
        'Groq Model',
        'Model used when Groq is selected.',
        'llama-3.3-70b-versatile',
        [
            'llama-3.1-8b-instant'                      => 'Llama 3.1 8B Instant — 560 t/s',
            'llama-3.3-70b-versatile'                    => 'Llama 3.3 70B Versatile — 280 t/s',
            'openai/gpt-oss-120b'                        => 'GPT OSS 120B (via Groq) — 500 t/s',
            'openai/gpt-oss-20b'                         => 'GPT OSS 20B (via Groq) — 1000 t/s',
            'meta-llama/llama-4-scout-17b-16e-instruct'  => 'Llama 4 Scout 17B [Preview] — 750 t/s',
            'qwen/qwen3-32b'                             => 'Qwen3 32B [Preview] — 400 t/s',
            'custom'                                     => 'Other (type manually below)',
        ]
    ));

    // ── OpenAI Models ─────────────────────────────────────────────────────────
    $settings->add(new admin_setting_configselect(
        'mod_ainotebook/model_openai',
        'OpenAI Model',
        'Model used when OpenAI is selected.',
        'gpt-4o',
        [
            'gpt-4o'        => 'GPT-4o',
            'gpt-4.1'       => 'GPT-4.1',
            'gpt-4-turbo'   => 'GPT-4 Turbo',
            'gpt-3.5-turbo' => 'GPT-3.5 Turbo',
            'custom'        => 'Other (type manually below)',
        ]
    ));

    // ── Gemini Models ─────────────────────────────────────────────────────────
    $settings->add(new admin_setting_configselect(
        'mod_ainotebook/model_gemini',
        'Gemini Model',
        'Model used when Google Gemini is selected.',
        'gemini-1.5-flash',
        [
            'gemini-3.1-pro-preview'          => 'Gemini 3.1 Pro (Preview)',
            'gemini-3.1-flash-lite'           => 'Gemini 3.1 Flash Lite',
            'gemini-3.1-flash-lite-preview'   => 'Gemini 3.1 Flash Lite (Preview)',
            'gemini-3.1-flash-live-preview'   => 'Gemini 3.1 Flash Live (Preview)',
            'gemini-3.1-flash-image-preview'  => 'Gemini 3.1 Flash Image (Preview)',
            'gemini-3.1-flash-tts-preview'    => 'Gemini 3.1 Flash TTS (Preview)',
            'gemini-3-pro-image-preview'      => 'Gemini 3 Pro Image (Preview)',
            'gemini-3-flash-preview'          => 'Gemini 3 Flash (Preview)',
            'gemini-2.5-pro'                  => 'Gemini 2.5 Pro',
            'gemini-2.5-flash'                => 'Gemini 2.5 Flash',
            'gemini-2.5-flash-lite'           => 'Gemini 2.5 Flash Lite',
            'gemini-2.5-flash-lite-preview-09-2025' => 'Gemini 2.5 Flash Lite (09-2025)',
            'gemini-2.5-flash-native-audio-preview-12-2025' => 'Gemini 2.5 Flash Native Audio (12-2025)',
            'gemini-2.5-flash-image'          => 'Gemini 2.5 Flash Image',
            'gemini-2.5-flash-preview-tts'    => 'Gemini 2.5 Flash TTS (Preview)',
            'gemini-2.5-pro-preview-tts'      => 'Gemini 2.5 Pro TTS (Preview)',
            'gemini-2.5-computer-use-preview-10-2025' => 'Gemini 2.5 Computer Use (Preview)',
            'gemini-2.0-flash'                => 'Gemini 2.0 Flash',
            'gemini-2.0-flash-lite'           => 'Gemini 2.0 Flash Lite',
            'gemini-1.5-pro'                  => 'Gemini 1.5 Pro',
            'gemini-1.5-flash'                => 'Gemini 1.5 Flash',
            'custom'                          => 'Other (type manually below)',
        ]
    ));

    // ── Custom model ID (only shown when "Other" is selected) ─────────────────
    $settings->add(new admin_setting_configtext(
        'mod_ainotebook/model_custom',
        'Custom Model ID',
        'Enter a custom model ID if you selected "Other" above.',
        '',
        PARAM_TEXT
    ));

    // ── General ───────────────────────────────────────────────────────────────
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

    // ─────────────────────────────────────────────────────────────────────────
    // JS visibility logic.
    //
    // Strategy: inject a <style> block into <head> BEFORE the page paints so
    // irrelevant rows are hidden from the very first frame (zero flicker).
    // Once JS takes over, the style block is cleared and real display logic runs.
    // ─────────────────────────────────────────────────────────────────────────
    if (!empty($PAGE)) {

        $saved_provider = get_config('mod_ainotebook', 'ai_provider') ?: 'groq';
        $saved_model    = get_config('mod_ainotebook', 'model_' . $saved_provider) ?: '';

        // Build CSS that hides non-active rows before JS runs.
        $pre_paint_css = '';
        foreach (['groq', 'openai', 'gemini'] as $p) {
            if ($p !== $saved_provider) {
                $pre_paint_css .= ".form-group:has([name='s_mod_ainotebook/model_{$p}']){display:none!important}";
            }
        }
        if ($saved_model !== 'custom') {
            $pre_paint_css .= ".form-group:has([name='s_mod_ainotebook/model_custom']){display:none!important}";
        }
        if ($saved_provider === 'moodle') {
            $pre_paint_css .= ".form-group:has([name='s_mod_ainotebook/api_key']){display:none!important}";
        }

        $pre_paint_css_json = json_encode($pre_paint_css);

        $PAGE->requires->js_init_code("
(function () {
    // ── Pre-paint: hide rows immediately so there is no flicker ──────────────
    var style = document.createElement('style');
    style.id  = 'ainb-prepaint';
    style.textContent = {$pre_paint_css_json};
    document.head.appendChild(style);

    // ── Helpers ───────────────────────────────────────────────────────────────
    function getEl(name) {
        return document.querySelector('[name=\"s_mod_ainotebook/' + name + '\"]');
    }
    function getRow(name) {
        var el = getEl(name);
        if (!el) return null;
        return el.closest('.form-group') || el.closest('.row') || el.parentElement.parentElement;
    }
    function show(row, visible) {
        if (row) row.style.display = visible ? '' : 'none';
    }

    // ── Main sync ─────────────────────────────────────────────────────────────
    function syncUI() {
        var providerEl = getEl('ai_provider');
        if (!providerEl) return;

        var provider = providerEl.value;

        // Show only the model row that matches the current provider.
        ['groq', 'openai', 'gemini'].forEach(function (p) {
            show(getRow('model_' + p), p === provider);
        });

        // API key row: hide for Moodle (managed externally).
        show(getRow('api_key'), provider !== 'moodle');

        // Custom model field: show only when active provider's model = 'custom'.
        var activeModelEl = getEl('model_' + provider);
        show(getRow('model_custom'), !!(activeModelEl && activeModelEl.value === 'custom'));
    }

    // ── Init (wait for Moodle to render the form) ─────────────────────────────
    function init() {
        var providerEl = getEl('ai_provider');
        if (!providerEl || providerEl.dataset.ainbInit) return false;
        providerEl.dataset.ainbInit = '1';

        // Remove pre-paint CSS and apply real logic.
        var prepaint = document.getElementById('ainb-prepaint');
        if (prepaint) prepaint.textContent = '';
        syncUI();

        providerEl.addEventListener('change', syncUI);

        // Re-sync when any model dropdown changes (for the custom field).
        document.querySelectorAll('[name^=\"s_mod_ainotebook/model_\"]').forEach(function (el) {
            el.addEventListener('change', syncUI);
        });

        return true;
    }

    var attempts = 0;
    var timer = setInterval(function () {
        attempts++;
        if (init() || attempts > 30) clearInterval(timer);
    }, 100);
})();
        ");
    }
}