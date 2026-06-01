<?php
/**
 * Weekly teacher alert scheduled task
 *
 * @package    mod_ainotebook
 * @copyright  2026 Tateta (samastanuswantara.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_ainotebook\task;

defined('MOODLE_INTERNAL') || die();

class weekly_teacher_alert extends \core\task\scheduled_task {

    public function get_name() {
        return get_string('task_weekly_teacher_alert', 'mod_ainotebook');
    }

    public function execute() {
        global $DB, $CFG;
        require_once($CFG->libdir . '/enrollib.php');

        mtrace("Starting Weekly AI Teacher Alert task...");

        // Get all ainotebook instances
        $notebooks = $DB->get_records('ainotebook');
        if (empty($notebooks)) {
            mtrace("No ainotebook instances found.");
            return;
        }

        $oneweekago = time() - (7 * 24 * 3600);
        $noreplyuser = \core_user::get_noreply_user();

        foreach ($notebooks as $notebook) {
            $cm = get_coursemodule_from_instance('ainotebook', $notebook->id);
            if (!$cm) continue;

            $context = \context_module::instance($cm->id);
            $course = $DB->get_record('course', ['id' => $cm->course]);

            // Get chats from the last 7 days
            $chats = $DB->get_records_select('ainotebook_chat', 'ainotebookid = ? AND timecreated > ?', [$notebook->id, $oneweekago]);
            if (count($chats) < 1) {
                mtrace("Not enough activity for notebook ID {$notebook->id} (Course: {$course->shortname}). Skipping.");
                continue;
            }

            mtrace("Analyzing " . count($chats) . " chats for notebook ID {$notebook->id}...");

            $chat_text = "";
            $student_ids = [];
            foreach ($chats as $chat) {
                $chat_text .= "- " . $chat->message . "\n";
                $student_ids[$chat->userid] = true;
            }
            $student_count = count($student_ids);

            // Prepare prompt for AI
            $system_prompt = "You are an AI teaching assistant analyzing a week of student queries for a course.\n";
            $system_prompt .= "Below is a list of questions asked by $student_count students in the past 7 days.\n";
            $system_prompt .= "Your task is to summarize their main struggles and frequently asked topics in a friendly, professional email to the lecturer.\n";
            $system_prompt .= "IMPORTANT RULES:\n";
            $system_prompt .= "1. Write the email strictly in English.\n";
            $system_prompt .= "2. Be concise but informative (around 2-3 paragraphs).\n";
            $system_prompt .= "3. Start with a greeting like 'Dear Lecturer,' and do NOT include the subject line in the body.\n";
            $system_prompt .= "4. Highlight the specific topics they struggled with and suggest reviewing them in class.\n";
            $system_prompt .= "5. Do NOT use markdown code blocks.\n";
            
            $provider = get_config('mod_ainotebook', 'ai_provider');
            if ($provider === 'moodle') {
                $aimanager = \core\di::get(\core_ai\manager::class);
                $action = new \core_ai\aiactions\generate_text(
                    contextid: $context->id,
                    userid: (int)get_admin()->id,
                    prompttext: $system_prompt . "\n\nStudent Queries:\n" . $chat_text
                );
                $response_obj = $aimanager->process_action($action);
                if ($response_obj->get_success()) {
                    $ai_report = $response_obj->get_response_data()['generatedcontent'];
                } else {
                    $ai_report = "";
                }
            } else {
                $ai_report = \mod_ainotebook\ai_client::custom_provider_request($provider, $system_prompt, "Student Queries:\n" . $chat_text);
            }

            // Cleanup AI response
            $ai_report = trim(preg_replace('/^```\w*\s*/', '', $ai_report));
            $ai_report = preg_replace('/```$/', '', $ai_report);

            if (empty($ai_report) || stripos($ai_report, 'AI Error') !== false || stripos($ai_report, 'currently assisting') !== false) {
                mtrace("Failed to generate AI report for notebook ID {$notebook->id} due to API limit/error.");
                continue;
            }

            // Get teachers (users who can view progress)
            $teachers = get_enrolled_users($context, 'mod/ainotebook:viewprogress');
            if (empty($teachers)) {
                mtrace("No teachers found with viewprogress capability for notebook ID {$notebook->id}.");
                continue;
            }

            $subject = get_string('email_weekly_alert_subject', 'mod_ainotebook', $course->shortname);
            
            foreach ($teachers as $teacher) {
                // Send email
                email_to_user($teacher, $noreplyuser, $subject, $ai_report, format_text_email($ai_report, FORMAT_MOODLE));
                mtrace("Sent alert email to teacher: " . $teacher->email);
            }
        }

        mtrace("Weekly AI Teacher Alert task completed.");
    }
}
