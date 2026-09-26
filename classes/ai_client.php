<?php
/**
 * @package    mod_ainotebook
 * @copyright  2026 Tateta (samastanuswantara.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_ainotebook;

defined('MOODLE_INTERNAL') || die();

use core_ai\manager;
use core_ai\aiactions\generate_text;

class ai_client {

    private static $streamed = false;

    public static function was_streamed(): bool {
        return self::$streamed;
    }

    /**
     * Get a response from the AI.
    /**
     * Safely fetch course module record by either CM ID or Instance ID.
     */
    public static function get_cm_safe(int $id): \stdClass {
        if ($id <= 0) {
            throw new \moodle_exception('invalidcoursemodule', 'error');
        }
        $cm = get_coursemodule_from_id('ainotebook', $id, 0, false, IGNORE_MISSING);
        if (!$cm) {
            $cm = get_coursemodule_from_instance('ainotebook', $id, 0, false, IGNORE_MISSING);
        }
        if (!$cm) {
            throw new \moodle_exception('invalidcoursemodule', 'error');
        }
        return $cm;
    }

    /**
     * Sanitize AI output to strip raw provider brand names, API key error messages, and raw URLs,
     * ensuring responses are strictly branded as DEMI AI.
     */
    public static function sanitize_ai_output(string $text): string {
        if (empty($text)) return $text;

        // If error message contains API key leak, unauthorized or provider platform URL, sanitize completely
        if (stripos($text, 'Incorrect API key') !== false || stripos($text, 'platform.openai.com') !== false || stripos($text, 'api-keys') !== false || stripos($text, 'invalid_api_key') !== false || stripos($text, 'unauthorized') !== false || stripos($text, 'trycloudflare.com') !== false || stripos($text, 'Could not resolve host') !== false) {
            return "DEMI AI service is currently unavailable. Please try again later or notify your instructor/admin.";
        }

        // Replace raw provider references in text if any
        $replacements = [
            '/https?:\/\/platform\.openai\.com[^\s]*/i' => '',
            '/\bOpenAI\b/i'   => 'DEMI AI',
            '/\bChatGPT\b/i'  => 'DEMI AI',
            '/\bGemini\b/i'   => 'DEMI AI',
            '/\bGroq\b/i'     => 'DEMI AI',
            '/\bAnthropic\b/i' => 'DEMI AI',
            '/\bClaude\b/i'   => 'DEMI AI',
        ];

        return preg_replace(array_keys($replacements), array_values($replacements), $text);
    }

    /**
     * Main entry point for generating AI response.
     */
    public static function get_response(int $cmid, int $userid, string $user_message, array $selected_file_ids = [], array $config = [], bool $stream = false, string $focus_topic = ''): array {
        self::$streamed = false;
        global $DB, $USER;

        $cm         = self::get_cm_safe($cmid);
        $course     = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
        $ainotebook = $DB->get_record('ainotebook', ['id' => $cm->instance], '*', MUST_EXIST);

        $fullname = fullname($USER);
        $sources_count = 1;

        // ── Fetch conversation history ─────────────────────────────────────────
        $history = $DB->get_records(
            'ainotebook_chat',
            ['ainotebookid' => $ainotebook->id, 'userid' => $USER->id],
            'timecreated DESC',
            '*',
            0,
            5
        );
        
        // Clean history artifacts to save tokens
        if ($history) {
            foreach ($history as $h) {
                $h->response = preg_replace('/```(?:json-quiz|json|mermaid)[\s\S]*?```/', '', $h->response);
                $h->response = preg_replace('/\[(?:SUMMARY|REPORT)_START\][\s\S]*?\[(?:SUMMARY|REPORT)_END\]/', '', $h->response);
                $h->response = preg_replace('/<suggestions>[\s\S]*?<\/suggestions>/', '', $h->response);
            }
        }

        // ── Extract Live Syllabus from Moodle Course Structure ────────────────
        $live_syllabus = [];
        try {
            $modinfo = get_fast_modinfo($course);
            foreach ($modinfo->get_section_info_all() as $secnum => $section) {
                if (!$section->uservisible) continue;
                $raw_name = !empty($section->name) ? trim($section->name) : get_section_name($course, $section);
                $sec_name = trim(strip_tags(format_string($raw_name)));
                if (empty($sec_name) || $sec_name === 'New section') {
                    $sec_name = ($secnum == 0) ? "Course Overview" : "Session " . sprintf("%02d", $secnum);
                }
                $modules = [];
                if (!empty($modinfo->sections[$secnum])) {
                    foreach ($modinfo->sections[$secnum] as $cmid_item) {
                        $item_cm = $modinfo->cms[$cmid_item];
                        if ($item_cm->uservisible && $item_cm->id != $cm->id) {
                            $modules[] = $item_cm->name . " (" . $item_cm->modname . ")";
                        }
                    }
                }
                $clean_summary = trim(strip_tags($section->summary ?? ''));
                $live_syllabus[] = [
                    'topic'   => $sec_name,
                    'summary' => $clean_summary,
                    'modules' => $modules,
                ];
            }
        } catch (\Throwable $t) {
            // Graceful fallback
        }

        // ── Extract Material Text Context (PDF, PPTX, DOCX, TXT) ─────────────
        $material_context = "";
        $actual_material_count = 0;
        try {
            $all_course_files = self::get_all_course_materials($course->id, $cmid);
            if (!empty($all_course_files)) {
                $target_files = [];
                if (!empty($selected_file_ids)) {
                    $selected_set = array_flip($selected_file_ids);
                    foreach ($all_course_files as $f) {
                        if (isset($selected_set[$f->get_id()])) {
                            $target_files[] = $f;
                        }
                    }
                }
                if (empty($target_files)) {
                    // Smart priority sorting based on active focus topic or query keywords (e.g. week 1, week 2, slide)
                    $search_terms = [];
                    if (!empty($focus_topic) && $focus_topic !== 'All Course Materials') {
                        $search_terms[] = strtolower($focus_topic);
                    }
                    if (preg_match('/(week\s*\d+|minggu\s*\d+|pertemuan\s*\d+|module\s*\d+|project\s*\d+|slide\s*week\s*\d+)/i', $user_message, $m)) {
                        $search_terms[] = strtolower($m[1]);
                    }

                    if (!empty($search_terms)) {
                        usort($all_course_files, function($a, $b) use ($search_terms) {
                            $nameA = strtolower($a->get_filename());
                            $nameB = strtolower($b->get_filename());
                            $scoreA = 0;
                            $scoreB = 0;
                            foreach ($search_terms as $term) {
                                $cleanTerm = preg_replace('/[^a-z0-9]/', '', $term);
                                $cleanA = preg_replace('/[^a-z0-9]/', '', $nameA);
                                $cleanB = preg_replace('/[^a-z0-9]/', '', $nameB);
                                if (!empty($cleanTerm)) {
                                    if (strpos($cleanA, $cleanTerm) !== false) $scoreA += 10;
                                    if (strpos($cleanB, $cleanTerm) !== false) $scoreB += 10;
                                }
                            }
                            return $scoreB <=> $scoreA;
                        });
                    }
                    $target_files = $all_course_files;
                }

                $context_blocks = [];
                // Prioritize top 6 matching files
                $target_files = array_slice($target_files, 0, 6);

                foreach ($target_files as $file) {
                    $filename = $file->get_filename();
                    $mimetype = $file->get_mimetype();
                    $extracted = "";

                    if ($mimetype === 'text/plain') {
                        $extracted = substr($file->get_content(), 0, 3000);
                    } elseif ($mimetype === 'application/pdf') {
                        $tempdir = make_temp_directory('mod_ainotebook');
                        $tmpfile = $tempdir . '/' . uniqid() . '.pdf';
                        try {
                            $file->copy_content_to($tmpfile);
                            $output = [];
                            $return_var = 0;
                            exec("pdftotext -layout " . escapeshellarg($tmpfile) . " - 2>/dev/null", $output, $return_var);
                            if ($return_var === 0 && !empty($output)) {
                                $extracted = substr(implode("\n", $output), 0, 3500);
                            }
                        } catch (\Throwable $e) {
                        } finally {
                            if (file_exists($tmpfile)) @unlink($tmpfile);
                        }
                    } elseif ($mimetype === 'application/vnd.openxmlformats-officedocument.presentationml.presentation' || strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'pptx') {
                        $tempdir = make_temp_directory('mod_ainotebook');
                        $tmpfile = $tempdir . '/' . uniqid() . '.pptx';
                        try {
                            $file->copy_content_to($tmpfile);
                            $extracted = substr(self::extract_pptx_text($tmpfile), 0, 3500);
                        } catch (\Throwable $e) {
                        } finally {
                            if (file_exists($tmpfile)) @unlink($tmpfile);
                        }
                    } elseif ($mimetype === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' || strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'docx') {
                        $tempdir = make_temp_directory('mod_ainotebook');
                        $tmpfile = $tempdir . '/' . uniqid() . '.docx';
                        try {
                            $file->copy_content_to($tmpfile);
                            $extracted = substr(self::extract_docx_text($tmpfile), 0, 3500);
                        } catch (\Throwable $e) {
                        } finally {
                            if (file_exists($tmpfile)) @unlink($tmpfile);
                        }
                    }

                    if (!empty($extracted)) {
                        $clean = trim(preg_replace('/\s+/', ' ', $extracted));
                        if (strlen($clean) > 20) {
                            $context_blocks[] = "[Lecture Slide/Document: {$filename}]:\n" . substr($clean, 0, 2500);
                            $actual_material_count++;
                        }
                    }
                }

                if (!empty($context_blocks)) {
                    $material_context = implode("\n\n---\n\n", $context_blocks);
                }
            }
        } catch (\Throwable $t) {
            // Graceful fallback
        }

        $sources_count = $actual_material_count > 0 ? $actual_material_count : (count($selected_file_ids) > 0 ? count($selected_file_ids) : count($live_syllabus));
        $activity_name = $course->fullname . " (" . $ainotebook->name . ")";
        if (!empty($focus_topic) && $focus_topic !== 'All Course Materials') {
            $activity_name .= " [Active Topic Focus: " . $focus_topic . "]";
        }

        // ── Pure DEMI Core AI Engine Integration (Port 8001) ─────────────────
        $engine_url = get_config('mod_ainotebook', 'demi_engine_url') ?: 'http://localhost:8001';
        $engine_key = get_config('mod_ainotebook', 'demi_engine_key') ?: 'demi_secret_engine_key_2026';

        $formatted_history = [];
        if ($history) {
            foreach (array_reverse($history) as $h) {
                $formatted_history[] = ['role' => 'user', 'content' => $h->message];
                $formatted_history[] = ['role' => 'assistant', 'content' => $h->response];
            }
        }

        $payload = json_encode([
            'user_id'          => (int) $userid,
            'course_id'        => (int) $course->id,
            'activity_id'      => (int) $cm->instance,
            'activity_name'    => $activity_name,
            'user_message'     => (string) $user_message,
            'live_syllabus'    => $live_syllabus,
            'material_context' => $material_context,
            'chat_history'     => $formatted_history,
            'stream'           => (bool) $stream,
        ]);

        $endpoint = rtrim($engine_url, '/') . '/api/v1/chat/tutor';
        $full_text = "";
        $buffer = "";

        $ch = curl_init($endpoint);
        $headers = [
            'X-Engine-API-Key: ' . $engine_key,
            'Content-Type: application/json',
            'Accept: application/json, text/event-stream',
        ];

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 180);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

        if (class_exists('\core\session\manager')) {
            @\core\session\manager::write_close();
        }

        if ($stream) {
            curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch_handle, $data) use (&$full_text, &$buffer) {
                $buffer .= $data;
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 1);
                    $line = trim($line);
                    if (strpos($line, 'data: ') === 0) {
                        $json_str = trim(substr($line, 6));
                        if ($json_str === '[DONE]' || $json_str === '') continue;
                        $json = json_decode($json_str, true);
                        if ($json && isset($json['chunk']) && $json['chunk'] !== '') {
                            $chunk = $json['chunk'];
                            $full_text .= $chunk;
                            self::$streamed = true;
                            echo "data: " . json_encode(['chunk' => $chunk]) . "\n\n";
                            @ob_flush();
                            flush();
                        }
                    }
                }
                return strlen($data);
            });
            curl_exec($ch);
        } else {
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $raw_response = curl_exec($ch);
            if ($raw_response !== false) {
                $res_data = json_decode($raw_response, true);
                if (isset($res_data['data']['response'])) {
                    $full_text = $res_data['data']['response'];
                } elseif (isset($res_data['response'])) {
                    $full_text = $res_data['response'];
                }
            }
        }

        $curl_errno = curl_errno($ch);
        $curl_error = curl_error($ch);
        $http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!$curl_errno && !empty($full_text)) {
            if (strpos($full_text, '```mermaid') !== false) {
                $full_text = preg_replace_callback(
                    '/```mermaid(.*?)```/s',
                    fn($m) => '```mermaid' . self::sanitize_mermaid($m[1]) . '```',
                    $full_text
                );
            }
            return ['response' => self::sanitize_ai_output($full_text), 'sources_count' => $sources_count];
        }

        if ($curl_errno) {
            debugging("mod_ainotebook: DEMI Engine native cURL failed: {$curl_error} (HTTP {$http_code}) URL: {$engine_url}", DEBUG_DEVELOPER);
            return ['response' => "⚠️ DEMI Core AI Engine is currently unreachable. Please try again later or contact your administrator.", 'sources_count' => 0];
        }

        debugging("mod_ainotebook: DEMI Engine unexpected response: HTTP {$http_code}", DEBUG_DEVELOPER);
        return ['response' => "⚠️ Received unexpected response from DEMI Core AI Engine. Please try again in a moment.", 'sources_count' => 0];
    }

    /**
     * Get unified chat history across demi-portal and moodle-ainotebook from demi-engine
     */
    public static function get_unified_history(int $cmid, int $userid): array {
        global $DB;
        $cm = get_coursemodule_from_id('ainotebook', $cmid, 0, false, MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

        $engine_url = get_config('mod_ainotebook', 'demi_engine_url') ?: 'http://localhost:8001';
        $engine_key = get_config('mod_ainotebook', 'demi_engine_key') ?: 'demi_secret_engine_key_2026';

        $payload = json_encode([
            'user_id'   => (int) $userid,
            'course_id' => (int) $course->id,
            'limit'     => 30,
        ]);

        $ch = curl_init(rtrim($engine_url, '/') . '/api/v1/chat/history');
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'X-Engine-API-Key: ' . $engine_key,
            'Content-Type: application/json',
            'Accept: application/json',
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 2);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

        $raw_response = @curl_exec($ch);
        $curl_errno   = curl_errno($ch);
        curl_close($ch);

        if (!$curl_errno && $raw_response) {
            $res_data = json_decode($raw_response, true);
            if (!empty($res_data['history']) && is_array($res_data['history'])) {
                $unified_list = [];
                foreach ($res_data['history'] as $item) {
                    $unified_list[] = (object)[
                        'message'     => $item['prompt'] ?? '',
                        'response'    => $item['response'] ?? '',
                        'timecreated' => !empty($item['created_at']) ? strtotime($item['created_at']) : time(),
                    ];
                }
                return $unified_list;
            }
        }

        $local_history = $DB->get_records('ainotebook_chat', ['ainotebookid' => $cm->instance, 'userid' => $userid], 'timecreated ASC');
        return array_values($local_history);
    }

    /**
     * Ensure session_id column exists in ainotebook_chat table.
     */
    public static function ensure_session_id_field(): void {
        global $DB;
        $dbman = $DB->get_manager();
        $table = new \xmldb_table('ainotebook_chat');
        $field = new \xmldb_field('session_id', XMLDB_TYPE_CHAR, '64', null, null, null, null);
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
    }

    /**
     * Get list of chat sessions for a user, grouped by session_id.
     */
    public static function get_sessions(int $cmid, int $userid): array {
        global $DB;
        self::ensure_session_id_field();
        $cm = self::get_cm_safe($cmid);
        $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

        // Backfill legacy records if any exist without session_id
        $empty_records = $DB->get_records_select('ainotebook_chat', "ainotebookid = ? AND userid = ? AND (session_id IS NULL OR session_id = '')", [$cm->instance, $userid], 'timecreated ASC');
        if (!empty($empty_records)) {
            $current_sess = '';
            $last_time = 0;
            foreach ($empty_records as $rec) {
                if (empty($current_sess) || ($rec->timecreated - $last_time > 1800)) {
                    $current_sess = 'sess_' . $rec->userid . '_' . $rec->timecreated;
                }
                $DB->set_field('ainotebook_chat', 'session_id', $current_sess, ['id' => $rec->id]);
                $last_time = $rec->timecreated;
            }
        }

        $records = $DB->get_records_sql("
            SELECT session_id,
                   MIN(id) AS first_id,
                   MAX(timecreated) AS last_timecreated,
                   COUNT(*) AS msg_count
              FROM {ainotebook_chat}
             WHERE ainotebookid = :ainotebookid AND userid = :userid AND session_id IS NOT NULL AND session_id != ''
          GROUP BY session_id
          ORDER BY last_timecreated DESC
        ", ['ainotebookid' => $cm->instance, 'userid' => $userid]);

        $sessions = [];
        foreach ($records as $r) {
            $first_msg = $DB->get_record('ainotebook_chat', ['id' => $r->first_id]);
            $title = 'Tutoring Session';
            if ($first_msg && !empty($first_msg->message)) {
                $raw_title = trim(strip_tags($first_msg->message));
                $raw_title = preg_replace('/```[\s\S]*?```/', '', $raw_title);
                $title = strlen($raw_title) > 50 ? substr($raw_title, 0, 47) . '...' : $raw_title;
                if (empty($title)) $title = 'Tutoring Session';
            }

            $diff = time() - $r->last_timecreated;
            if ($diff < 60) $rel_time = 'Just now';
            elseif ($diff < 3600) $rel_time = floor($diff / 60) . ' mins ago';
            elseif ($diff < 86400) $rel_time = floor($diff / 3600) . ' hours ago';
            else $rel_time = floor($diff / 86400) . ' days ago';

            $sessions[] = [
                'session_id'      => $r->session_id,
                'course_fullname' => s($course->fullname),
                'title'           => s($title),
                'time'            => date('h:i A', $r->last_timecreated),
                'rel_time'        => $rel_time,
                'message_count'   => (int) $r->msg_count,
                'updated_at_ts'   => $r->last_timecreated
            ];
        }
        return $sessions;
    }

    /**
     * Get messages belonging to a specific session_id.
     */
    public static function get_session_messages(int $cmid, int $userid, string $session_id): array {
        global $DB;
        self::ensure_session_id_field();
        $cm = self::get_cm_safe($cmid);

        $records = $DB->get_records('ainotebook_chat', [
            'ainotebookid' => $cm->instance,
            'userid' => $userid,
            'session_id' => $session_id
        ], 'timecreated ASC');

        $messages = [];
        foreach ($records as $r) {
            $clean_response = preg_replace('/<script[\s\S]*?<\/script>/i', '', $r->response);
            $messages[] = [
                'id'           => $r->id,
                'user_message' => $r->message,
                'ai_response'  => $clean_response,
                'time'         => date('h:i A', $r->timecreated),
                'timecreated'  => $r->timecreated
            ];
        }
        return $messages;
    }


    // ─────────────────────────────────────────────────────────────────────────
    // Custom provider request
    // Now accepts a structured messages array for proper multi-turn history.
    // ─────────────────────────────────────────────────────────────────────────

    // ─────────────────────────────────────────────────────────────────────────
    // Pure DEMI Core AI Engine Request
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Send direct prompt/chat request to central DEMI Core AI Engine (FastAPI Port 8001).
     */
    public static function demi_engine_request(
        string $system_prompt,
        string $user_message,
        array  $history = [],
        bool   $stream = false,
        int    $user_id = 0,
        int    $course_id = 0,
        int    $activity_id = 0,
        string $activity_name = ''
    ): string {
        $engine_url = get_config('mod_ainotebook', 'demi_engine_url') ?: 'http://localhost:8001';
        $engine_key = get_config('mod_ainotebook', 'demi_engine_key') ?: 'demi_secret_engine_key_2026';

        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        $curl = new \curl();
        $curl->setopt([
            'CURLOPT_TIMEOUT'        => 180,
            'CURLOPT_CONNECTTIMEOUT' => 15,
            'CURLOPT_HTTPHEADER'     => [
                'X-Engine-API-Key: ' . $engine_key,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);

        $formatted_history = [];
        foreach ($history as $h) {
            if (is_object($h)) {
                $formatted_history[] = ['role' => 'user', 'content' => $h->message ?? ''];
                $formatted_history[] = ['role' => 'assistant', 'content' => $h->response ?? ''];
            } elseif (is_array($h)) {
                $formatted_history[] = [
                    'role' => $h['role'] ?? 'user',
                    'content' => $h['content'] ?? ($h['parts'][0]['text'] ?? '')
                ];
            }
        }

        $full_message = $user_message;
        if (!empty($system_prompt)) {
            $full_message = "[System Instruction: {$system_prompt}]\n\n" . $user_message;
        }

        $payload = json_encode([
            'user_id'       => (int) ($user_id ?: 1001),
            'course_id'     => (int) $course_id,
            'activity_id'   => (int) $activity_id,
            'activity_name' => (string) ($activity_name ?: 'DEMI Academic Tutor'),
            'user_message'  => (string) $full_message,
            'chat_history'  => $formatted_history,
            'stream'        => (bool) $stream,
        ]);

        if ($stream) {
            $endpoint = rtrim($engine_url, '/') . '/api/v1/chat/tutor';
            $buffer = "";
            $full_text = "";
            
            $curl->setopt([
                'CURLOPT_WRITEFUNCTION' => function($ch, $data) use (&$full_text, &$buffer) {
                    $buffer .= $data;
                    while (($pos = strpos($buffer, "\n")) !== false) {
                        $line = substr($buffer, 0, $pos);
                        $buffer = substr($buffer, $pos + 1);
                        $line = trim($line);
                        if (strpos($line, 'data: ') === 0) {
                            $json_str = trim(substr($line, 6));
                            if ($json_str === '[DONE]') continue;
                            $json = json_decode($json_str, true);
                            if ($json && isset($json['chunk']) && $json['chunk'] !== '') {
                                $chunk = $json['chunk'];
                                $full_text .= $chunk;
                                self::$streamed = true;
                                echo "data: " . json_encode(['chunk' => $chunk]) . "\n\n";
                                @ob_flush();
                                flush();
                            }
                        }
                    }
                    return strlen($data);
                }
            ]);

            $raw_response = $curl->post($endpoint, $payload);
            if (!$curl->errno && !empty($full_text)) {
                if (strpos($full_text, '```mermaid') !== false) {
                    $full_text = preg_replace_callback(
                        '/```mermaid(.*?)```/s',
                        fn($m) => '```mermaid' . self::sanitize_mermaid($m[1]) . '```',
                        $full_text
                    );
                }
                return self::sanitize_ai_output($full_text);
            }
        } else {
            $endpoint = rtrim($engine_url, '/') . '/api/v1/chat/tutor';
            $raw_response = $curl->post($endpoint, $payload);

            if (!$curl->errno) {
                $res_data = json_decode($raw_response, true);
                if (isset($res_data['data']['response'])) {
                    $ai_text = $res_data['data']['response'];
                    if (strpos($ai_text, '```mermaid') !== false) {
                        $ai_text = preg_replace_callback(
                            '/```mermaid(.*?)```/s',
                            fn($m) => '```mermaid' . self::sanitize_mermaid($m[1]) . '```',
                            $ai_text
                        );
                    }
                    return self::sanitize_ai_output($ai_text);
                }
            }
        }

        if ($curl->errno) {
            debugging("mod_ainotebook: DEMI Engine request failed: " . $curl->error . " URL: " . $engine_url, DEBUG_DEVELOPER);
            return "⚠️ DEMI Core AI Engine is currently unreachable. Please try again later or contact your administrator.";
        }

        debugging("mod_ainotebook: DEMI Engine unexpected response: " . print_r($curl->response, true), DEBUG_DEVELOPER);
        return "⚠️ Received unexpected response from DEMI Core AI Engine. Please try again in a moment.";
    }

    /**
     * Backward-compatible alias for custom_provider_request routing to pure DEMI Engine.
     */
    public static function custom_provider_request(
        string $provider,
        string $system_prompt,
        string $user_message,
        array  $history = [],
        array  $binaries = [],
        bool   $stream = false
    ): string {
        return self::demi_engine_request($system_prompt, $user_message, $history, $stream);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Suggestions
    // ─────────────────────────────────────────────────────────────────────────

    public static function get_suggestions(int $cmid, int $userid, array $selected_file_ids = []): array {
        global $DB;

        $cm          = get_coursemodule_from_id('ainotebook', $cmid, 0, false, MUST_EXIST);
        $ainotebookid = $cm->instance;

        // [IMPROVED] Consistent history depth with get_response() (was 3, now 5).
        $history = $DB->get_records(
            'ainotebook_chat',
            ['ainotebookid' => $ainotebookid, 'userid' => $userid],
            'timecreated DESC',
            '*',
            0,
            5
        );
        
        // Clean history artifacts to save tokens
        if ($history) {
            foreach ($history as $h) {
                $h->response = preg_replace('/```(?:json-quiz|json|mermaid)[\s\S]*?```/', '[AI Generated Artifact Hidden]', $h->response);
                $h->response = preg_replace('/\[REPORT_START\][\s\S]*?\[REPORT_END\]/', '[AI Generated Report Hidden]', $h->response);
            }
        }

        $history_context = "";
        $history_hash    = "";
        if ($history) {
            foreach (array_reverse($history) as $h) {
                $history_context .= "User: " . $h->message . "\nAI: " . $h->response . "\n";
                $history_hash    .= $h->id;
            }
        }

        $cache     = \cache::make('mod_ainotebook', 'suggestions');
        $cache_key = $cmid . '_' . $userid . '_' . md5($history_hash);
        $cached    = $cache->get($cache_key);
        if ($cached !== false) {
            return $cached;
        }

        // Fallback for suggestions if no RAG is available or empty prompt
        $material = "Please suggest questions based on the course topic.";

        $system_prompt = "You are a helpful academic assistant. Suggest 3 brief follow-up questions a student might ask next. STRICT RULES: Each suggestion MUST NOT EXCEED 10 WORDS. Each suggestion MUST be in English. Reply ONLY with the questions, one per line. No numbers, no bullet points, no preamble.";

        $user_prompt = "";
        if ($history_context) {
            $user_prompt .= "Conversation History:\n" . $history_context . "\n";
        }
        $user_prompt .= "Study Materials:\n" . substr($material, 0, 2000);

        $response = self::demi_engine_request(
            $system_prompt,
            $user_prompt,
            [],
            false,
            $userid,
            $cm->course,
            $cm->instance,
            $cm->name
        );

        if (empty($response) || stripos($response, 'AI Error') !== false || stripos($response, 'The AI service') !== false || stripos($response, 'I encountered') !== false || stripos($response, 'I am having') !== false || stripos($response, 'DEMI Tutor is currently assisting') !== false) {
            $suggestions = []; // Force empty to trigger fallback
        } else {
            $suggestions = array_filter(array_map('trim', explode("\n", $response)));
            $suggestions = array_values(array_slice($suggestions, 0, 3));

            // Enforce word count limit as a backend safety net.
            $suggestions = array_map(function (string $s): string {
                $words = preg_split('/\s+/', $s);
                return count($words) > 10 ? implode(' ', array_slice($words, 0, 10)) : $s;
            }, $suggestions);
        }

        // If suggestions are empty, we return an empty array instead of fallback defaults.

        $cache->set($cache_key, $suggestions);
        return $suggestions;
    }

    /**
     * Get all course materials across the entire course (mod_ainotebook, mod_resource, mod_folder).
     */
    public static function get_all_course_materials(int $courseid, int $cmid): array {
        global $DB;
        $fs = get_file_storage();
        $all_files = [];

        // 1. Files uploaded directly to this mod_ainotebook instance
        $mod_context = \context_module::instance($cmid, IGNORE_MISSING);
        if ($mod_context) {
            $ain_files = $fs->get_area_files($mod_context->id, 'mod_ainotebook', 'files', 0, 'id ASC', false);
            foreach ($ain_files as $f) {
                if (!$f->is_directory() && $f->get_filesize() > 0) {
                    $all_files[$f->get_id()] = $f;
                }
            }
        }

        // 2. All resource files and folder files across the course
        $mod_resources = $DB->get_records_sql("
            SELECT cm.id AS cmid, cm.module, m.name AS modname
            FROM {course_modules} cm
            JOIN {modules} m ON m.id = cm.module
            WHERE cm.course = :courseid AND cm.deletioninprogress = 0
            AND m.name IN ('resource', 'folder')
        ", ['courseid' => $courseid]);

        if ($mod_resources) {
            foreach ($mod_resources as $mod) {
                $c_ctx = \context_module::instance($mod->cmid, IGNORE_MISSING);
                if ($c_ctx) {
                    $area_files = $fs->get_area_files($c_ctx->id, 'mod_' . $mod->modname, 'content', 0, 'id ASC', false);
                    foreach ($area_files as $f) {
                        if (!$f->is_directory() && $f->get_filesize() > 0) {
                            $all_files[$f->get_id()] = $f;
                        }
                    }
                }
            }
        }

        return array_values($all_files);
    }

    public static function get_context_material(int $cmid): string {
        $cm = get_coursemodule_from_id('ainotebook', $cmid, 0, false, MUST_EXIST);
        $files = self::get_all_course_materials($cm->course, $cmid);
        
        if (empty($files)) {
            return "No study documents are currently uploaded for this activity.";
        }
        
        $context_text = "\nAvailable course documents in this workspace:\n";
        foreach ($files as $file) {
            if ($file->is_directory()) continue;
            $context_text .= "- " . $file->get_filename() . "\n";
        }
        return $context_text;
    }


    public static function evaluate_student(int $cmid, int $target_userid): array {
        global $DB;
        
        $cm = get_coursemodule_from_id('ainotebook', $cmid, 0, false, MUST_EXIST);
        $ainotebookid = $cm->instance;
        $target_user = $DB->get_record('user', ['id' => $target_userid], '*', MUST_EXIST);
        
        $history = $DB->get_records('ainotebook_chat', ['ainotebookid' => $ainotebookid, 'userid' => $target_userid], 'timecreated ASC', '*', 0, 50);
        $artifacts = $DB->get_records('ainotebook_artifacts', ['ainotebookid' => $ainotebookid, 'userid' => $target_userid], 'timecreated ASC');
        
        if (empty($history) && empty($artifacts)) {
            return [
                'score' => 0,
                'understanding' => 'No activity found.',
                'activity_summary' => 'The student has not interacted with the AI yet.',
                'recommendation' => 'Encourage the student to start asking questions.'
            ];
        }
        
        $user_prompt = "Student Name: " . fullname($target_user) . "\n\n";
        $user_prompt .= "--- CHAT HISTORY ---\n";
        foreach ($history as $h) {
            $user_prompt .= "Student: " . $h->message . "\n";
        }
        $user_prompt .= "\n--- GENERATED ARTIFACTS ---\n";
        foreach ($artifacts as $a) {
            $user_prompt .= "- Type: " . $a->type . ", Title: " . $a->title . "\n";
        }
        
        $system_prompt = "You are an Academic Evaluator. Your task is to analyze a student's interaction history with an AI study assistant and evaluate their learning progress.\n";
        $system_prompt .= "Based on their questions and the artifacts they generated, determine their level of understanding, activity, and assign a score.\n";
        $system_prompt .= "STRICT INSTRUCTIONS:\n";
        $system_prompt .= "1. You MUST respond ONLY with a raw JSON object.\n";
        $system_prompt .= "2. DO NOT wrap the JSON in markdown code blocks (no ```json ... ```). Output the JSON directly.\n";
        $system_prompt .= "3. The JSON must exactly match this structure:\n";
        $system_prompt .= "{\n";
        $system_prompt .= "  \"score\": <integer between 0 and 100>,\n";
        $system_prompt .= "  \"understanding\": \"<2-3 sentences evaluating their comprehension based on the depth of their questions>\",\n";
        $system_prompt .= "  \"activity_summary\": \"<1-2 sentences summarizing their activity level and artifacts>\",\n";
        $system_prompt .= "  \"recommendation\": \"<1 sentence actionable advice for the teacher>\"\n";
        $system_prompt .= "}\n";
        
        $response = self::demi_engine_request(
            $system_prompt,
            $user_prompt,
            [],
            false,
            $target_userid,
            $cm->course,
            $cm->instance,
            'Academic Evaluator'
        );
        
        $json_text = trim($response);
        $first_brace = strpos($json_text, '{');
        $last_brace = strrpos($json_text, '}');
        if ($first_brace !== false && $last_brace !== false && $last_brace > $first_brace) {
            $json_text = substr($json_text, $first_brace, $last_brace - $first_brace + 1);
        }
        
        $json = json_decode($json_text, true);
        if (!$json || !isset($json['score'])) {
            // Check if response starts with "Error" or looks like a known error message
            if (strpos($response, 'Error:') === 0 || strpos($response, 'AI Error:') === 0 || strpos($response, 'I am having trouble') === 0 || strpos($response, 'The AI service') === 0 || strpos($response, 'I encountered') === 0) {
                throw new \Exception($response);
            }
            // Check for rate limits specifically
            if (stripos($response, 'DEMI Tutor is currently assisting') !== false || stripos($response, 'AI service is currently unavailable') !== false) {
                throw new \Exception('DEMI Tutor is currently assisting many students. Please wait a few moments and try again.');
            }
            // Generic parse error, append first 200 chars of response for context
            $debug_response = substr(trim(strip_tags($response)), 0, 200);
            if (empty($debug_response)) {
                $debug_response = 'Empty response received from the AI service.';
            }
            throw new \Exception('Failed to parse AI evaluation response. Response received: ' . $debug_response);
        }
        
        $eval = $DB->get_record('ainotebook_evals', ['ainotebookid' => $ainotebookid, 'userid' => $target_userid]);
        if ($eval) {
            $eval->score = (int)$json['score'];
            $eval->insight_json = json_encode($json);
            $eval->timemodified = time();
            $DB->update_record('ainotebook_evals', $eval);
        } else {
            $eval = new \stdClass();
            $eval->ainotebookid = $ainotebookid;
            $eval->userid = $target_userid;
            $eval->score = (int)$json['score'];
            $eval->insight_json = json_encode($json);
            $eval->timecreated = time();
            $eval->timemodified = time();
            $DB->insert_record('ainotebook_evals', $eval);
        }
        
        // Push the score to the Moodle Gradebook
        require_once(__DIR__ . '/../lib.php');
        $ainotebook = $DB->get_record('ainotebook', ['id' => $ainotebookid], '*', MUST_EXIST);
        $ainotebook->cmidnumber = $cm->idnumber;
        
        $grade = new \stdClass();
        $grade->userid = $target_userid;
        $grade->rawgrade = (float)$json['score'];
        \ainotebook_grade_item_update($ainotebook, $grade);
        
        return $json;
    }



    // ─────────────────────────────────────────────────────────────────────────
    // Mermaid sanitizer — fixes common AI-generated syntax errors before render
    // ─────────────────────────────────────────────────────────────────────────

    public static function sanitize_mermaid(string $code): string {
        $lines = explode("\n", trim($code));
        $out   = [];

        foreach ($lines as $line) {
            $trimmed = rtrim($line);

            // 1. Fix node labels containing () without double quotes.
            //    e.g. A[User Experience (UX) Design]  →  A["User Experience (UX) Design"]
            //    Also catches nested parens. Only fix unquoted labels.
            $trimmed = preg_replace_callback(
                '/([A-Za-z0-9_]+)\[([^\]"]*\([^\]]*\)[^\]"]*)\]/',
                function ($m) {
                    // Already quoted? skip.
                    if (strpos($m[2], '"') !== false) return $m[0];
                    return $m[1] . '["' . $m[2] . '"]';
                },
                $trimmed
            );

            // 2. Fix stray > after closing pipe: -->|Label|> ID  →  -->|Label| ID
            $trimmed = preg_replace('/\|>\s*/', '| ', $trimmed);

            // 3. Fix missing space before target node ID: -->|Label|B[  →  -->|Label| B[
            $trimmed = preg_replace('/(\|)([A-Za-z_][A-Za-z0-9_]*)\[/', '$1 $2[', $trimmed);

            // 4. Split chained connections onto separate lines.
            //    e.g. A[X] -->|y| B[Z] -->|w| C[V]  →  two lines
            //    Detect: closing bracket or ID followed by space then --> on same line.
            if (substr_count($trimmed, '-->') > 1) {
                // Split after each "]" or node-ID that is followed by " -->"
                $parts = preg_split('/(?<=[\]A-Za-z0-9_])\s+(?=[A-Za-z0-9_]+\s*-->|[A-Za-z0-9_]+\[)/', $trimmed);
                // Rebuild: first part is "A -->|x| B", rest start new connections
                $rebuilt = [];
                $carry   = '';
                foreach ($parts as $part) {
                    $part = trim($part);
                    if (preg_match('/^[A-Za-z0-9_]+(\[|-->)/', $part) && $carry !== '') {
                        $rebuilt[] = '    ' . $carry;
                        $carry = $part;
                    } else {
                        $carry = $carry === '' ? $part : $carry . ' ' . $part;
                    }
                }
                if ($carry !== '') $rebuilt[] = '    ' . $carry;
                if (count($rebuilt) > 1) {
                    foreach ($rebuilt as $r) $out[] = $r;
                    continue;
                }
            }

            // 5. Remove trailing connectors with no target: "A -->|Label|" at end of line
            $trimmed = preg_replace('/-->\s*\|[^|]+\|\s*$/', '', $trimmed);

            // 6. Remove duplicate double-arrows
            $trimmed = preg_replace('/-->\s*-->/', '-->', $trimmed);

            if (trim($trimmed) !== '') {
                $out[] = $trimmed;
            }
        }

        return "\n" . implode("\n", $out) . "\n";
    }

    /**
     * Generate embeddings for a file.
     * Splits the text into chunks and uses the Gemini API to get vectors.
     */
    public static function generate_embeddings_for_file(int $ainotebookid, int $fileid, string $text): void {
        global $DB;

        // Retrieve the filename from Moodle file storage
        $fs = get_file_storage();
        $file = $fs->get_file_by_id($fileid);
        $filename = $file ? $file->get_filename() : "document.pdf";

        // Clean up text
        $text = preg_replace("/\r\n|\r/", "\n", $text);
        
        // Split text by page break character (\f)
        $pages = explode("\f", $text);
        $chunk_index = 0;

        $all_chunks = [];

        foreach ($pages as $page_idx => $page_content) {
            $page_num = $page_idx + 1;
            
            // Chunking per page: max 1000 characters preserving paragraph boundaries
            $chunks = [];
            $current_chunk = "";
            $paragraphs = explode("\n", $page_content);
            foreach ($paragraphs as $p) {
                $p = trim($p);
                if (empty($p)) continue;
                
                if (strlen($current_chunk) + strlen($p) > 1000) {
                    if (!empty($current_chunk)) {
                        $chunks[] = $current_chunk;
                    }
                    $current_chunk = $p;
                } else {
                    $current_chunk .= (empty($current_chunk) ? "" : "\n") . $p;
                }
            }
            if (!empty($current_chunk)) {
                $chunks[] = $current_chunk;
            }

            foreach ($chunks as $chunk_text) {
                // Add page metadata block to the text chunk content
                $formatted_text = "[Source: {$filename} - Page {$page_num}]\n" . $chunk_text;
                $all_chunks[] = [
                    'chunk_index' => $chunk_index,
                    'text_content' => $formatted_text
                ];
                $chunk_index++;
            }
        }

        if (empty($all_chunks)) {
            return;
        }

        // Fetch existing chunk indexes for this file
        $existing_chunks = $DB->get_fieldset_select('ainotebook_embeddings', 'chunk_index', 'fileid = ?', [$fileid]);
        $existing_set = array_flip($existing_chunks);

        // Filter out already embedded chunks
        $missing_chunks = [];
        foreach ($all_chunks as $c) {
            if (!isset($existing_set[$c['chunk_index']])) {
                $missing_chunks[] = $c;
            }
        }

        if (empty($missing_chunks)) {
            return;
        }

        // Batch embed the missing chunks in groups of 50
        $batch_size = 50;
        $chunks_count = count($missing_chunks);
        for ($i = 0; $i < $chunks_count; $i += $batch_size) {
            $batch = array_slice($missing_chunks, $i, $batch_size);
            $texts = array_map(function($c) { return $c['text_content']; }, $batch);
            
            $vectors = self::generate_embeddings_batch($texts);
            if ($vectors && count($vectors) === count($batch)) {
                foreach ($batch as $idx => $c) {
                    $record = new \stdClass();
                    $record->ainotebookid = $ainotebookid;
                    $record->fileid = $fileid;
                    $record->chunk_index = $c['chunk_index'];
                    $record->text_content = $c['text_content'];
                    $record->embedding = json_encode($vectors[$idx]);
                    $record->timecreated = time();
                    
                    $DB->insert_record('ainotebook_embeddings', $record);
                }
            } else {
                // Fallback to single requests if batching fails or is not supported
                foreach ($batch as $c) {
                    $vector = self::generate_embedding_for_text($c['text_content']);
                    if ($vector) {
                        $record = new \stdClass();
                        $record->ainotebookid = $ainotebookid;
                        $record->fileid = $fileid;
                        $record->chunk_index = $c['chunk_index'];
                        $record->text_content = $c['text_content'];
                        $record->embedding = json_encode($vector);
                        $record->timecreated = time();
                        
                        $DB->insert_record('ainotebook_embeddings', $record);
                    }
                }
            }
        }
    }

    public static function process_all_materials(int $cmid): void {
        global $DB;

        $cm = self::get_cm_safe($cmid);
        $files = self::get_all_course_materials($cm->course, $cmid);

        if (empty($files)) {
            return;
        }

        $binaries = [];

        foreach ($files as $file) {
            if ($file->is_directory()) {
                continue;
            }

            // Check if file is already embedded
            $existing = $DB->get_record('ainotebook_embeddings', ['fileid' => $file->get_id()], '*', IGNORE_MULTIPLE);
            if ($existing) {
                continue; // Already processed
            }

            $mimetype  = $file->get_mimetype();
            $filename  = $file->get_filename();
            $extracted = "";

            if ($mimetype === 'text/plain') {
                $extracted = $file->get_content();
            } elseif ($mimetype === 'application/pdf') {
                // Strategy 0: Collect Base64 for multimodal.
                if ($file->get_filesize() < 5 * 1024 * 1024) {
                    $binaries[] = [
                        'mimetype' => 'application/pdf',
                        'data'     => base64_encode($file->get_content()),
                        'filename' => $filename
                    ];
                }
                $tempdir = make_temp_directory('mod_ainotebook');
                $tmpfile = $tempdir . '/' . uniqid() . '.pdf';
                try {
                    $file->copy_content_to($tmpfile);
                    
                    // Strategy 1: Layout-aware extraction (best for AI context)
                    $output     = [];
                    $return_var = 0;
                    exec("pdftotext -layout " . escapeshellarg($tmpfile) . " - 2>/dev/null", $output, $return_var);
                    $extracted = implode("\n", $output);
 
                    // Strategy 2: If layout failed or returned empty, try raw extraction
                    if ($return_var !== 0 || trim($extracted) === '') {
                        $output = [];
                        exec("pdftotext -raw " . escapeshellarg($tmpfile) . " - 2>/dev/null", $output, $return_var);
                        if ($return_var === 0) {
                            $extracted = implode("\n", $output);
                        }
                    }
 
                    // Strategy 3: OCR Fallback (for scanned images)
                    if (trim($extracted) === '' || strlen(trim($extracted)) < 50) {
                        $imgbase = $tempdir . '/' . uniqid() . '-page';
                        // Convert first 5 pages to images (balanced for performance/quality)
                        exec("pdftoppm -f 1 -l 5 -r 300 " . escapeshellarg($tmpfile) . " " . escapeshellarg($imgbase) . " 2>/dev/null");
                        
                        $ocr_text = "";
                        $images = glob($imgbase . "*.ppm"); 
                        sort($images); 
                        
                        foreach ($images as $img) {
                            $output_ocr = [];
                            // Run tesseract with both English and Indonesian support.
                            exec("tesseract -l eng+ind " . escapeshellarg($img) . " stdout 2>/dev/null", $output_ocr);
                            $ocr_text .= implode("\n", $output_ocr) . "\f";
                            @unlink($img); 
                        }
                        
                        if (strlen(trim($ocr_text)) > 50) {
                            $extracted = "[OCR Extracted Text (Eng+Ind)]:\n" . $ocr_text;
                        }
                    }

                    if (trim($extracted) === '') {
                        $extracted = "[System Note: Document empty or non-extractable.]";
                    }
                } catch (\Exception $e) {
                    $extracted = "[Error: " . $e->getMessage() . "]";
                } finally {
                    if (file_exists($tmpfile)) {
                        @unlink($tmpfile);
                    }
                }
            } elseif ($mimetype === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' || strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'docx') {
                $tempdir = make_temp_directory('mod_ainotebook');
                $tmpfile = $tempdir . '/' . uniqid() . '.docx';
                try {
                    $file->copy_content_to($tmpfile);
                    $extracted = self::extract_docx_text($tmpfile);
                } catch (\Exception $e) {
                    $extracted = "[Error: " . $e->getMessage() . "]";
                } finally {
                    if (file_exists($tmpfile)) {
                        @unlink($tmpfile);
                    }
                }
            } elseif ($mimetype === 'application/vnd.openxmlformats-officedocument.presentationml.presentation' || strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'pptx') {
                $tempdir = make_temp_directory('mod_ainotebook');
                $tmpfile = $tempdir . '/' . uniqid() . '.pptx';
                try {
                    $file->copy_content_to($tmpfile);
                    $extracted = self::extract_pptx_text($tmpfile);
                } catch (\Exception $e) {
                    $extracted = "[Error: " . $e->getMessage() . "]";
                } finally {
                    if (file_exists($tmpfile)) {
                        @unlink($tmpfile);
                    }
                }
            }

            if (!empty($extracted)) {
                // Ingest text to embedding index
                $cm = get_coursemodule_from_id('ainotebook', $cmid, 0, false, MUST_EXIST);
                self::generate_embeddings_for_file($cm->instance, $file->get_id(), $extracted);
            }
        }
    }

    /**
     * Compute cosine similarity between two vectors.
     */
    public static function cosine_similarity(array $vecA, array $vecB): float {
        $dotProduct = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        
        $count = min(count($vecA), count($vecB));
        for ($i = 0; $i < $count; $i++) {
            $a = $vecA[$i];
            $b = $vecB[$i];
            $dotProduct += $a * $b;
            $normA += $a * $a;
            $normB += $b * $b;
        }
        
        if ($normA == 0.0 || $normB == 0.0) return 0.0;
        return $dotProduct / (sqrt($normA) * sqrt($normB));
    }

    /**
     * Search knowledge index for relevant chunks.
     */
    public static function search_knowledge(int $ainotebookid, array $file_ids, string $query, int $top_k): array {
        global $DB;
        
        if (empty($file_ids)) {
            $file_ids = $DB->get_fieldset_select('ainotebook_embeddings', 'DISTINCT fileid', 'ainotebookid = ?', [$ainotebookid]);
        }
        if (empty($file_ids)) return [];

        // Pre-fetch raw records as fallback
        $raw_records = $DB->get_records_select('ainotebook_embeddings', 'ainotebookid = ?', [$ainotebookid], '', '*', 0, $top_k * 2);
        if (empty($raw_records)) return [];
        
        $query_vector = self::generate_embedding_for_text($query);
        if (empty($query_vector)) {
            return array_slice(array_values($raw_records), 0, $top_k);
        }
        
        $scored_chunks = [];
        $cache = \cache::make('mod_ainotebook', 'material_context');
        
        foreach ($file_ids as $file_id) {
            $cache_key = "file_embeddings_v2_" . $file_id;
            $cached_chunks = $cache->get($cache_key);
            
            if ($cached_chunks === false) {
                // Fetch from DB if not in cache
                $chunks = $DB->get_records('ainotebook_embeddings', ['fileid' => $file_id]);
                if (empty($chunks)) continue;
                
                $cached_chunks = [];
                foreach ($chunks as $chunk) {
                    $vector = json_decode($chunk->embedding, true);
                    if (!is_array($vector)) continue;
                    
                    $chunk_arr = (array)$chunk;
                    $chunk_arr['vector'] = $vector; // store decoded array
                    unset($chunk_arr['embedding']); // remove heavy JSON string
                    $cached_chunks[] = $chunk_arr; // Must be array for simpledata=true cache
                }
                $cache->set($cache_key, $cached_chunks);
            }
            
            // Calculate cosine similarity using the cached decoded arrays
            foreach ($cached_chunks as $chunk_arr) {
                $c = (object)$chunk_arr; // cast back to object for downstream code
                $score = self::cosine_similarity($query_vector, $c->vector);
                $c->score = $score;
                $scored_chunks[] = $c;
            }
        }

        if (empty($scored_chunks)) {
            return array_slice(array_values($raw_records), 0, $top_k);
        }
        
        usort($scored_chunks, function($a, $b) {
            return $b->score <=> $a->score;
        });

        $filtered = array_filter($scored_chunks, fn($c) => $c->score >= 0.1);
        if (empty($filtered)) {
            $filtered = $scored_chunks;
        }
        
        return array_slice(array_values($filtered), 0, $top_k);
    }

    /**
     * Generate embeddings for a given text using DEMI Core AI Engine.
     */
    public static function generate_embedding_for_text(string $text): ?array {
        $engine_url = get_config('mod_ainotebook', 'demi_engine_url') ?: 'http://localhost:8001';
        $engine_key = get_config('mod_ainotebook', 'demi_engine_key') ?: 'demi_secret_engine_key_2026';

        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        $curl = new \curl();
        $curl->setopt([
            'CURLOPT_TIMEOUT'    => 30,
            'CURLOPT_HTTPHEADER' => [
                'X-Engine-API-Key: ' . $engine_key,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);

        $endpoint = rtrim($engine_url, '/') . '/api/v1/rag/ingest';
        $raw_response = $curl->post($endpoint, json_encode(['content' => $text, 'topic_name' => 'General']));
        $result = json_decode($raw_response, true);
        if (isset($result['status']) && $result['status'] === 'success') {
            return [1.0];
        }

        return null;
    }

    /**
     * Generate embeddings for multiple texts in a single batch request using DEMI Core AI Engine.
     * @param array $texts Array of strings.
     * @return array|null Array of embedding arrays, or null on failure.
     */
    public static function generate_embeddings_batch(array $texts): ?array {
        if (empty($texts)) {
            return [];
        }

        $vectors = [];
        foreach ($texts as $text) {
            $emb = self::generate_embedding_for_text($text);
            if ($emb) {
                $vectors[] = $emb;
            }
        }
        return !empty($vectors) ? $vectors : null;
    }

    public static function extract_docx_text(string $filepath): string {
        $zip = new \ZipArchive();
        if ($zip->open($filepath) === true) {
            $xml = $zip->getFromName('word/document.xml');
            $zip->close();
            if ($xml) {
                $xml = str_replace(['</w:p>', '</w:r>', '<w:tab/>'], ["\n", " ", "    "], $xml);
                $text = strip_tags($xml);
                return html_entity_decode(trim($text));
            }
        }
        return "";
    }

    public static function extract_pptx_text(string $filepath): string {
        $zip = new \ZipArchive();
        if ($zip->open($filepath) === true) {
            $slides_text = [];
            for ($i = 1; $i <= 1000; $i++) {
                $slide_xml = $zip->getFromName("ppt/slides/slide{$i}.xml");
                if (!$slide_xml) {
                    break;
                }
                $slide_xml = str_replace(['</a:p>', '</a:t>'], ["\n", " "], $slide_xml);
                $text = strip_tags($slide_xml);
                $slides_text[] = html_entity_decode(trim($text));
            }
            $zip->close();
            if (!empty($slides_text)) {
                return implode("\f", $slides_text);
            }
        }
        return "";
    }

    /**
     * Synchronize a video URL or Bunny Stream link directly to DEMI Engine RAG
     */
    public static function sync_video_material(int $course_id, string $topic, string $video_url, string $filename = ''): bool {
        $engine_url = get_config('mod_ainotebook', 'demi_engine_url') ?: 'http://localhost:8001';
        $engine_key = get_config('mod_ainotebook', 'demi_engine_key') ?: 'demi_secret_engine_key_2026';

        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        $curl = new \curl();
        $curl->setopt([
            'CURLOPT_TIMEOUT'    => 30,
            'CURLOPT_HTTPHEADER' => [
                'X-Engine-API-Key: ' . $engine_key,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);

        $payload = json_encode([
            'course_id' => $course_id,
            'files' => [
                [
                    'url' => $video_url,
                    'topic' => $topic,
                    'filename' => $filename ?: 'Video_Lecture_' . substr(md5($video_url), 0, 8) . '.vtt',
                ]
            ]
        ]);

        $endpoint = rtrim($engine_url, '/') . '/api/v1/rag/sync-moodle-files';
        $raw_response = $curl->post($endpoint, $payload);
        $res = json_decode($raw_response, true);
        return isset($res['status']) && in_array($res['status'], ['success', 'partial_success']);
    }
}