<?php
/**
 * @package    mod_ainotebook
 * @copyright  2026 Tateta (samastanuswantara.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_ainotebook;

defined('MOODLE_INTERNAL') || die();

class rate_limiter {

    /**
     * Estimates token count based on the standard English/Indonesian average.
     * Roughly 1 word = 1.33 tokens.
     */
    public static function estimate_tokens(string $text): int {
        if (empty($text)) {
            return 0;
        }
        $words = str_word_count(strip_tags($text));
        return (int) ceil($words * 1.33);
    }

    /**
     * Checks if the user is within their configured rate limits.
     * Throws an exception with a friendly error message if exceeded.
     */
    public static function enforce_limits(int $userid, int $estimated_input_tokens = 0, $course = null): void {
        global $DB, $USER;

        // Ensure table exists (upgrade might not have run yet)
        $dbman = $DB->get_manager();
        if (!$dbman->table_exists('ainotebook_api_logs')) {
            return; // Skip limit check if table doesn't exist
        }

        $rpm = (int) get_config('mod_ainotebook', 'limit_rpm');
        $rpd = (int) get_config('mod_ainotebook', 'limit_rpd');
        $tpm = (int) get_config('mod_ainotebook', 'limit_tpm');

        $trial_rpd = (int) get_config('mod_ainotebook', 'limit_trial_rpd');
        if ($trial_rpd <= 0) {
            $trial_rpd = 10;
        }

        $trial_cat_id = (int) get_config('mod_ainotebook', 'trial_category_id');
        if ($trial_cat_id <= 0) {
            $trial_cat_id = 11;
        }

        $now = time();
        $one_minute_ago = $now - 60;
        $one_day_ago = $now - 86400;

        // Determine if active session/user is a Trial / Candidate student
        $is_trial_user = false;

        if (isguestuser($USER) || (isset($USER->idnumber) && strtolower($USER->idnumber) === 'candidate')) {
            $is_trial_user = true;
        }

        if ($course) {
            $course_obj = is_numeric($course) ? $DB->get_record('course', ['id' => (int)$course]) : $course;
            if ($course_obj) {
                if ((int)($course_obj->category ?? 0) === $trial_cat_id ||
                    str_contains(strtolower($course_obj->shortname ?? ''), 'trial') ||
                    str_contains(strtolower($course_obj->fullname ?? ''), 'trial')) {
                    $is_trial_user = true;
                }
            }
        }

        // 1. Enforce Trial / Candidate Daily Limit (default 10 questions/day)
        if ($is_trial_user && $trial_rpd > 0) {
            $req_today = $DB->count_records_select('ainotebook_api_logs', 'userid = ? AND timecreated > ?', [$userid, $one_day_ago]);
            if ($req_today >= $trial_rpd) {
                throw new \Exception("DEMI AI Companion Trial limit ({$trial_rpd} questions/day) reached. Please verify your student profile for Unlimited Access!");
            }
        }

        // 2. Check Requests Per Minute (RPM)
        if ($rpm > 0) {
            $req_min = $DB->count_records_select('ainotebook_api_logs', 'userid = ? AND timecreated > ?', [$userid, $one_minute_ago]);
            if ($req_min >= $rpm) {
                throw new \Exception("Rate Limit Exceeded: You have reached the maximum allowed requests per minute. Please wait a moment and try again.");
            }
        }

        // 3. Check Requests Per Day (RPD) for regular students
        if (!$is_trial_user && $rpd > 0) {
            $req_day = $DB->count_records_select('ainotebook_api_logs', 'userid = ? AND timecreated > ?', [$userid, $one_day_ago]);
            if ($req_day >= $rpd) {
                throw new \Exception("Rate Limit Exceeded: You have reached your daily limit for AI requests. Please try again tomorrow.");
            }
        }

        // 4. Check Tokens Per Minute (TPM)
        if ($tpm > 0) {
            // Count tokens used in the last minute PLUS the tokens we are about to use.
            $sql = "SELECT SUM(tokens) AS total FROM {ainotebook_api_logs} WHERE userid = ? AND timecreated > ?";
            $record = $DB->get_record_sql($sql, [$userid, $one_minute_ago]);
            $tokens_used = $record->total ? (int) $record->total : 0;
            
            if (($tokens_used + $estimated_input_tokens) > $tpm) {
                throw new \Exception("Rate Limit Exceeded: You have reached the maximum allowed tokens per minute. Please wait a moment and try shorter prompts.");
            }
        }
    }

    /**
     * Logs the API request usage.
     */
    public static function log_request(int $userid, int $total_tokens): void {
        global $DB;
        
        $dbman = $DB->get_manager();
        if (!$dbman->table_exists('ainotebook_api_logs')) {
            return;
        }

        $log = new \stdClass();
        $log->userid = $userid;
        $log->tokens = $total_tokens;
        $log->timecreated = time();
        $DB->insert_record('ainotebook_api_logs', $log);
    }
}
