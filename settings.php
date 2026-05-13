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

    // Combined Model List for PHP validation.
    $all_models = array(
        // Updated Groq Models (Llama 3.1).
        'llama-3.1-8b-instant' => 'Llama 3.1 8B (Instant)',
        'llama-3.1-70b-versatile' => 'Llama 3.1 70B (Versatile)',
        'llama3-70b-8192' => 'Llama 3 70B (Legacy)',
        'mixtral-8x7b-32768' => 'Mixtral 8x7B',
        'gemma2-9b-it' => 'Gemma 2 9B',
        'qwen-2.5-32b' => 'Qwen 2.5 32B (Reasoning)',
        'llama-guard-3-8b' => 'Llama Guard 3 8B (Security)',
        // OpenAI.
        'gpt-4o' => 'GPT-4o',
        'gpt-4-turbo' => 'GPT-4 Turbo',
        'gpt-3.5-turbo' => 'GPT-3.5 Turbo',
        // Gemini.
        'gemini-1.5-pro' => 'Gemini 1.5 Pro',
        'gemini-1.5-flash' => 'Gemini 1.5 Flash',
        'gemini-pro' => 'Gemini Pro',
        'custom' => 'Other (Type manually below)'
    );

    $settings->add(new admin_setting_configselect('mod_ainotebook/model_name',
        'Select Model',
        'The available models will update based on the provider selected above.',
        'llama-3.1-8b-instant',
        $all_models
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

    // JS Helper for dynamic filtering of the SINGLE dropdown.
    if (!empty($PAGE)) {
        $js = '
        (function() {
            var findEl = function(part) {
                var name = "s_mod_ainotebook/" + part;
                var el = document.getElementsByName(name)[0];
                if (el) return el;
                return document.querySelector("select[name*=\'" + part + "\']") || 
                       document.querySelector("input[name*=\'" + part + "\']") ||
                       document.querySelector("[id*=\'" + part + "\']");
            };

            var init = function() {
                var providerEl = findEl("ai_provider");
                var modelEl = findEl("model_name");
                var customEl = findEl("model_custom");
                
                if (!providerEl || !modelEl) return false;

                var modelData = {
                    "groq": [
                        {val: "llama-3.1-8b-instant", text: "Llama 3.1 8B (Instant)"},
                        {val: "llama-3.1-70b-versatile", text: "Llama 3.1 70B (Versatile)"},
                        {val: "llama3-70b-8192", text: "Llama 3 70B (Legacy)"},
                        {val: "mixtral-8x7b-32768", text: "Mixtral 8x7B"},
                        {val: "gemma2-9b-it", text: "Gemma 2 9B"},
                        {val: "qwen-2.5-32b", text: "Qwen 2.5 32B (Reasoning)"},
                        {val: "llama-guard-3-8b", text: "Llama Guard 3 8B (Security)"}
                    ],
                    "openai": [
                        {val: "gpt-4o", text: "GPT-4o"},
                        {val: "gpt-4-turbo", text: "GPT-4 Turbo"},
                        {val: "gpt-3.5-turbo", text: "GPT-3.5 Turbo"}
                    ],
                    "gemini": [
                        {val: "gemini-1.5-pro", text: "Gemini 1.5 Pro"},
                        {val: "gemini-1.5-flash", text: "Gemini 1.5 Flash"},
                        {val: "gemini-pro", text: "Gemini Pro"}
                    ],
                    "moodle": []
                };

                var initialVal = modelEl.value;

                function updateModels(e) {
                    var provider = providerEl.value;
                    var models = modelData[provider] || [];
                    modelEl.options.length = 0;
                    models.forEach(function(m) {
                        modelEl.options.add(new Option(m.text, m.val));
                    });
                    if (provider !== "moodle") {
                        modelEl.options.add(new Option("Other (Type manually below)", "custom"));
                    }
                    if (e === "init" && initialVal) modelEl.value = initialVal;

                    var modelRow = modelEl.closest(".form-group") || modelEl.closest(".row") || modelEl.parentElement.parentElement;
                    if (modelRow) modelRow.style.display = (provider === "moodle") ? "none" : "";
                    
                    if (customEl) {
                        var customRow = customEl.closest(".form-group") || customEl.closest(".row") || customEl.parentElement.parentElement;
                        if (customRow) customRow.style.display = (modelEl.value === "custom") ? "" : "none";
                    }
                }

                providerEl.addEventListener("change", updateModels);
                modelEl.addEventListener("change", updateModels);
                updateModels("init");
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
