<?php
/**
 * @package    mod_ainotebook
 * @copyright  2024 Tateta
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    // AI Branding.
    $settings->add(new admin_setting_configtext('mod_ainotebook/ai_name',
        'AI Name',
        'The display name of your AI Assistant (e.g. PresMate).',
        'PresMate',
        PARAM_TEXT
    ));

    // Selection of AI Provider.
    $settings->add(new admin_setting_configselect('mod_ainotebook/ai_provider',
        'AI Provider',
        'Select which AI provider to use.',
        'groq',
        array(
            'groq' => 'Groq (Fastest)',
            'openai' => 'OpenAI',
            'gemini' => 'Google Gemini',
            'moodle' => 'Moodle AI Subsystem (Default)'
        )
    ));

    // API Key.
    $settings->add(new admin_setting_configpasswordunmask('mod_ainotebook/api_key',
        'API Key',
        'Your API Key for the selected provider (Leave empty if using Moodle AI).',
        ''
    ));

    // AI Provider Models (Separate settings for each to avoid JS flickering).
    $settings->add(new admin_setting_configselect('mod_ainotebook/model_groq',
        'Groq Model',
        'This model will be used when Groq is selected as the AI Provider.',
        'llama-3.1-8b-instant',
        array(
            'llama-3.1-8b-instant' => 'Llama 3.1 8B (Instant)',
            'llama-3.1-70b-versatile' => 'Llama 3.1 70B (Versatile)',
            'llama3-70b-8192' => 'Llama 3 70B (Legacy)',
            'mixtral-8x7b-32768' => 'Mixtral 8x7B',
            'gemma2-9b-it' => 'Gemma 2 9B',
            'qwen-2.5-32b' => 'Qwen 2.5 32B (Reasoning)',
            'llama-guard-3-8b' => 'Llama Guard 3 8B (Security)',
            'custom' => 'Other (Type manually below)'
        )
    ));

    $settings->add(new admin_setting_configselect('mod_ainotebook/model_openai',
        'OpenAI Model',
        'This model will be used when OpenAI is selected as the AI Provider.',
        'gpt-4o',
        array(
            'gpt-4o' => 'GPT-4o',
            'gpt-4-turbo' => 'GPT-4 Turbo',
            'gpt-3.5-turbo' => 'GPT-3.5 Turbo',
            'custom' => 'Other (Type manually below)'
        )
    ));

    $settings->add(new admin_setting_configselect('mod_ainotebook/model_gemini',
        'Gemini Model',
        'This model will be used when Google Gemini is selected as the AI Provider.',
        'gemini-1.5-flash',
        array(
            'gemini-1.5-pro' => 'Gemini 1.5 Pro',
            'gemini-1.5-flash' => 'Gemini 1.5 Flash',
            'gemini-pro' => 'Gemini Pro',
            'custom' => 'Other (Type manually below)'
        )
    ));

    // Custom Model Fallback.
    $settings->add(new admin_setting_configtext('mod_ainotebook/model_custom',
        'Other / Custom Model ID',
        'Enter a custom model ID if you selected "Other" above.',
        '',
        PARAM_TEXT
    ));

    // General Plugin Settings.
    $settings->add(new admin_setting_heading('mod_ainotebook/general_heading', 
        'General Settings', 
        ''
    ));

    $settings->add(new admin_setting_configcheckbox('mod_ainotebook/autoadd',
        'Auto-add to new courses',
        'If enabled, the AI Notebook activity will be automatically added to all newly created courses.',
        1
    ));

    // JS for simple visibility toggling (Zero flickering).
    if (!empty($PAGE)) {
        $js = '
        (function() {
            var init = function() {
                var providerEl = document.querySelector(\'[name="s_mod_ainotebook/ai_provider"]\');
                if (!providerEl || providerEl.dataset.ainotebookInit) return false;

                var getRow = function(name) {
                    var el = document.querySelector(\'[name="s_mod_ainotebook/\' + name + \'"]\');
                    return el ? (el.closest(".form-group") || el.closest(".row") || el.parentElement.parentElement) : null;
                };

                function syncUI() {
                    var provider = providerEl.value;
                    var rows = {
                        "groq": getRow("model_groq"),
                        "openai": getRow("model_openai"),
                        "gemini": getRow("model_gemini"),
                        "custom": getRow("model_custom")
                    };

                    if (rows.groq) rows.groq.style.display = (provider === "groq") ? "" : "none";
                    if (rows.openai) rows.openai.style.display = (provider === "openai") ? "" : "none";
                    if (rows.gemini) rows.gemini.style.display = (provider === "gemini") ? "" : "none";
                    
                    if (rows.custom) {
                        var currentModelEl = document.querySelector(\'[name="s_mod_ainotebook/model_\' + provider + \'"]\');
                        rows.custom.style.display = (currentModelEl && currentModelEl.value === "custom") ? "" : "none";
                    }
                }

                providerEl.addEventListener("change", syncUI);
                document.querySelectorAll(\'[name^="s_mod_ainotebook/model_"]\').forEach(function(el) {
                    el.addEventListener("change", syncUI);
                });

                syncUI();
                providerEl.dataset.ainotebookInit = "true";
                return true;
            };

            var attempts = 0;
            var timer = setInterval(function() {
                attempts++;
                if (init() || attempts > 20) clearInterval(timer);
            }, 300);
        })();';
        $PAGE->requires->js_init_code($js);
    }
}
