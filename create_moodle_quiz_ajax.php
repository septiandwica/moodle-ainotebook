<?php
/**
 * @package    mod_ainotebook
 * @copyright  2026 Tateta
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->libdir . '/questionlib.php');

$cmid = required_param('cmid', PARAM_INT);
$name = required_param('name', PARAM_TEXT);
$intro = required_param('intro', PARAM_RAW);
$quizdata_json = required_param('quizdata', PARAM_RAW);

$cm = get_coursemodule_from_id('ainotebook', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);

require_login($course, true, $cm);
require_sesskey();

$context = context_module::instance($cm->id);
require_capability('mod/ainotebook:viewprogress', $context); // Must be teacher

$quizdata = json_decode($quizdata_json);
if (!$quizdata || !isset($quizdata->questions) || empty($quizdata->questions)) {
    echo json_encode(['success' => false, 'error' => 'Invalid or empty quiz data']);
    exit;
}

try {
    // 1. Create Course Module for Quiz
    $quizmodule = $DB->get_record('modules', ['name' => 'quiz'], '*', MUST_EXIST);
    
    // Create quiz record
    $quiz = new stdClass();
    $quiz->course = $course->id;
    $quiz->name = $name;
    $quiz->intro = $intro;
    $quiz->introformat = FORMAT_HTML;
    $quiz->timeopen = 0;
    $quiz->timeclose = 0;
    $quiz->timelimit = 0;
    $quiz->overduehandling = 'autosubmit';
    $quiz->graceperiod = 0;
    $quiz->preferredbehaviour = 'deferredfeedback';
    $quiz->attempts = 0;
    $quiz->grademethod = 1;
    $quiz->decimalpoints = 2;
    $quiz->questiondecimalpoints = -1;
    $quiz->questionsperpage = 1;
    $quiz->navmethod = 'free';
    $quiz->sumgrades = count($quizdata->questions);
    $quiz->grade = 100;
    $quiz->timecreated = time();
    $quiz->timemodified = time();
    
    // Add required Moodle 4.x display options (0x11100 = 69888 = during, immediately, later, closed)
    $quiz->reviewattempt = 69888;
    $quiz->reviewcorrectness = 69888;
    $quiz->reviewmarks = 69888;
    $quiz->reviewspecificfeedback = 69888;
    $quiz->reviewgeneralfeedback = 69888;
    $quiz->reviewrightanswer = 69888;
    $quiz->reviewoverallfeedback = 69888;
    $quiz->reviewmaxmarks = 69888;
    $quiz->shuffleanswers = 1;
    $quiz->questionsperpage = 1;
    
    $quiz->id = $DB->insert_record('quiz', $quiz);
    
    // Create cm record
    $newcm = new stdClass();
    $newcm->course = $course->id;
    $newcm->module = $quizmodule->id;
    $newcm->instance = $quiz->id;
    // Get current section
    $current_section = $DB->get_record('course_sections', ['id' => $cm->section]);
    $sectionnum = $current_section ? $current_section->section : 1;

    $newcm->section = 1; // Temporary placeholder
    $newcm->idnumber = '';
    $newcm->added = time();
    $newcm->visible = 1;
    $newcm->visibleold = 1;
    
    $newcm->id = $DB->insert_record('course_modules', $newcm);
    
    // Assign to section
    $section = $DB->get_record('course_sections', ['course' => $course->id, 'section' => $sectionnum]);
    if (!$section) {
        course_create_sections_if_missing($course, [$sectionnum]);
        $section = $DB->get_record('course_sections', ['course' => $course->id, 'section' => $sectionnum]);
    }
    $newcm->section = $section->id;
    $DB->update_record('course_modules', $newcm);
    course_add_cm_to_section($course, $newcm->id, $sectionnum);
    
    // Update quiz with coursemodule id
    $quiz->coursemodule = $newcm->id;
    $DB->update_record('quiz', $quiz);
    
    rebuild_course_cache($course->id, true);
    
    // Create grade item
    quiz_update_grades($quiz, 0, false);
    
    // 2. Create Question Category for this Quiz
    $quizcontext = context_module::instance($newcm->id);
    $category = new stdClass();
    $category->contextid = $quizcontext->id;
    $category->name = "AI Generated Questions";
    $category->info = "Questions generated from AI Notebook";
    $category->parent = 0;
    $category->stamp = make_unique_id_code();
    $category->sortorder = 999;
    $category->id = $DB->insert_record('question_categories', $category);
    
    // Create quiz section (Moodle requires at least one section for slots to display)
    $qsection = new stdClass();
    $qsection->quizid = $quiz->id;
    $qsection->firstslot = 1;
    $qsection->heading = '';
    $qsection->shufflequestions = 0;
    $DB->insert_record('quiz_sections', $qsection);

    // 3. Create Questions and add to Quiz
    $page = 1;
    foreach ($quizdata->questions as $q) {
        $qtext = $q->text ?? $q->question ?? "Question";
        $type = strtolower($q->type ?? 'multichoice');
        $options = $q->options ?? [];
        $answer = $q->answer;
        
        $question = new stdClass();
        $question->category = $category->id;
        $question->parent = 0;
        $question->name = substr(strip_tags($qtext), 0, 50) . '...';
        $question->questiontext = $qtext;
        $question->questiontextformat = FORMAT_HTML;
        $question->generalfeedback = '';
        $question->generalfeedbackformat = FORMAT_HTML;
        $question->defaultmark = 1.0;
        $question->penalty = 0.3333333;
        $question->length = 1;
        $question->stamp = make_unique_id_code();
        $question->version = make_unique_id_code();
        $question->hidden = 0;
        $question->timecreated = time();
        $question->timemodified = time();
        $question->createdby = $USER->id;
        $question->modifiedby = $USER->id;
        
        if ($type === 'truefalse') {
            $question->qtype = 'truefalse';
            $question->id = $DB->insert_record('question', $question);
            
            // Moodle 4.x Question Bank Entry
            $bankentry = new stdClass();
            $bankentry->questioncategoryid = $category->id;
            $bankentry->idnumber = make_unique_id_code();
            
            $bankentry->ownerid = $USER->id;
            $bankentry->id = $DB->insert_record('question_bank_entries', $bankentry);
            
            $qversion = new stdClass();
            $qversion->questionbankentryid = $bankentry->id;
            $qversion->version = 1;
            $qversion->questionid = $question->id;
            $qversion->status = 'ready';
            $DB->insert_record('question_versions', $qversion);
            
            // truefalse needs 2 answers in question_answers
            $ansTrue = new stdClass();
            $ansTrue->question = $question->id;
            $ansTrue->answer = 'True';
            $ansTrue->answerformat = FORMAT_HTML;
            $ansTrue->fraction = (strtolower((string)$answer) === 'true' || $answer === true) ? 1.0 : 0.0;
            $ansTrue->feedback = '';
            $ansTrue->feedbackformat = FORMAT_HTML;
            $trueId = $DB->insert_record('question_answers', $ansTrue);
            
            $ansFalse = new stdClass();
            $ansFalse->question = $question->id;
            $ansFalse->answer = 'False';
            $ansFalse->answerformat = FORMAT_HTML;
            $ansFalse->fraction = (strtolower((string)$answer) === 'false' || $answer === false) ? 1.0 : 0.0;
            $ansFalse->feedback = '';
            $ansFalse->feedbackformat = FORMAT_HTML;
            $falseId = $DB->insert_record('question_answers', $ansFalse);
            
            $tf = new stdClass();
            $tf->question = $question->id;
            $tf->trueanswer = $trueId;
            $tf->falseanswer = $falseId;
            $DB->insert_record('question_truefalse', $tf);
            
        } elseif ($type === 'essay') {
            $question->qtype = 'essay';
            $question->id = $DB->insert_record('question', $question);
            
            // Moodle 4.x Question Bank Entry
            $bankentry = new stdClass();
            $bankentry->questioncategoryid = $category->id;
            $bankentry->idnumber = make_unique_id_code();
            
            $bankentry->ownerid = $USER->id;
            $bankentry->id = $DB->insert_record('question_bank_entries', $bankentry);
            
            $qversion = new stdClass();
            $qversion->questionbankentryid = $bankentry->id;
            $qversion->version = 1;
            $qversion->questionid = $question->id;
            $qversion->status = 'ready';
            $DB->insert_record('question_versions', $qversion);
            
            $es = new stdClass();
            $es->questionid = $question->id;
            $es->responseformat = 'editor';
            $es->responserequired = 1;
            $es->responsefieldlines = 15;
            $es->attachments = 0;
            $es->attachmentsrequired = 0;
            $es->graderinfo = is_string($answer) ? $answer : '';
            $es->graderinfoformat = FORMAT_HTML;
            $es->responsetemplate = '';
            $es->responsetemplateformat = FORMAT_HTML;
            $DB->insert_record('qtype_essay_options', $es);
            
        } elseif ($type === 'shortanswer') {
            $question->qtype = 'shortanswer';
            $question->id = $DB->insert_record('question', $question);
            
            // Moodle 4.x Question Bank Entry
            $bankentry = new stdClass();
            $bankentry->questioncategoryid = $category->id;
            $bankentry->idnumber = make_unique_id_code();
            
            $bankentry->ownerid = $USER->id;
            $bankentry->id = $DB->insert_record('question_bank_entries', $bankentry);
            
            $qversion = new stdClass();
            $qversion->questionbankentryid = $bankentry->id;
            $qversion->version = 1;
            $qversion->questionid = $question->id;
            $qversion->status = 'ready';
            $DB->insert_record('question_versions', $qversion);
            
            $sa = new stdClass();
            $sa->questionid = $question->id;
            $sa->usecase = 0;
            $DB->insert_record('qtype_shortanswer_options', $sa);
            
            $ans = new stdClass();
            $ans->question = $question->id;
            $ans->answer = is_string($answer) ? $answer : '*';
            $ans->answerformat = FORMAT_HTML;
            $ans->fraction = 1.0;
            $ans->feedback = '';
            $ans->feedbackformat = FORMAT_HTML;
            $DB->insert_record('question_answers', $ans);
            
        } else {
            // Default to multichoice
            $question->qtype = 'multichoice';
            $question->id = $DB->insert_record('question', $question);
            
            // Moodle 4.x Question Bank Entry
            $bankentry = new stdClass();
            $bankentry->questioncategoryid = $category->id;
            $bankentry->idnumber = make_unique_id_code();
            
            $bankentry->ownerid = $USER->id;
            $bankentry->id = $DB->insert_record('question_bank_entries', $bankentry);
            
            $qversion = new stdClass();
            $qversion->questionbankentryid = $bankentry->id;
            $qversion->version = 1;
            $qversion->questionid = $question->id;
            $qversion->status = 'ready';
            $DB->insert_record('question_versions', $qversion);
            
            $mc = new stdClass();
            $mc->questionid = $question->id;
            $mc->layout = 0;
            $mc->single = 1;
            $mc->shuffleanswers = 1;
            $mc->correctfeedback = '';
            $mc->correctfeedbackformat = FORMAT_HTML;
            $mc->partiallycorrectfeedback = '';
            $mc->partiallycorrectfeedbackformat = FORMAT_HTML;
            $mc->incorrectfeedback = '';
            $mc->incorrectfeedbackformat = FORMAT_HTML;
            $mc->answernumbering = 'abc';
            $DB->insert_record('qtype_multichoice_options', $mc);
            
            $correctIndex = 0;
            if (is_string($answer)) {
                $upper = trim(strtoupper($answer));
                if (strlen($upper) === 1 && $upper >= 'A' && $upper <= 'E') {
                    $correctIndex = ord($upper) - 65;
                } else {
                    $correctIndex = intval($upper);
                }
            } else {
                $correctIndex = intval($answer);
            }
            
            if (empty($options)) {
                $options = ["Option A", "Option B", "Option C", "Option D"];
            }
            
            foreach ($options as $idx => $optText) {
                $ans = new stdClass();
                $ans->question = $question->id;
                $ans->answer = $optText;
                $ans->answerformat = FORMAT_HTML;
                $ans->fraction = ($idx === $correctIndex) ? 1.0 : 0.0;
                $ans->feedback = '';
                $ans->feedbackformat = FORMAT_HTML;
                $DB->insert_record('question_answers', $ans);
            }
        }
        
        // Manually link the question to the quiz slot (Moodle 4.x compatible)
        $slot = new stdClass();
        $slot->quizid = $quiz->id;
        $slot->slot = $page;
        $slot->page = $page;
        $slot->requireprevious = 0;
        $slot->maxmark = $question->defaultmark;
        $slot->questionid = $question->id; // Required for cache/backward compatibility
        $slot->questioncategoryid = $category->id; // Required in some Moodle versions
        $slot->id = $DB->insert_record('quiz_slots', $slot);
        
        $ref = new stdClass();
        $ref->usingcontextid = $quizcontext->id;
        $ref->component = 'mod_quiz';
        $ref->questionarea = 'slot';
        $ref->itemid = $slot->id;
        $ref->questionbankentryid = $bankentry->id;
        $ref->version = null; // Always use latest version
        $DB->insert_record('question_references', $ref);
        $page++; // 1 question per page
    }
    
    // Update quiz sumgrades based on inserted questions
    $quiz->sumgrades = $page - 1;
    $DB->update_record('quiz', $quiz);
    quiz_update_grades($quiz, 0, false);
    
    $url = new moodle_url('/course/modedit.php', ['update' => $newcm->id, 'return' => 1]);
    echo json_encode(['success' => true, 'url' => $url->out(false)]);
    
} catch (Exception $e) {
    $errorMsg = $e->getMessage();
    if (isset($e->debuginfo)) {
        $errorMsg .= ' | Debug: ' . $e->debuginfo;
    }
    echo json_encode(['success' => false, 'error' => $errorMsg]);
}
