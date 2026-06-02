<?php
/**
 * @package    mod_ainotebook
 * @copyright  2026 Tateta (samastanuswantara.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id = required_param('id', PARAM_INT); // Course Module ID.

$cm = get_coursemodule_from_id('ainotebook', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);
$ainotebook = $DB->get_record('ainotebook', array('id' => $cm->instance), '*', MUST_EXIST);
$config = get_config('mod_ainotebook');
$ai_name = empty($config->ai_name) ? 'DEMI TUTOR' : $config->ai_name;

require_login($course, true, $cm);
$context = context_module::instance($cm->id);

$viewself = optional_param('viewself', 0, PARAM_INT);
$req_userid = optional_param('userid', 0, PARAM_INT);

$is_teacher = has_capability('mod/ainotebook:viewprogress', $context);
$is_readonly = false;
$target_user = $USER;

if ($is_teacher && $req_userid && $req_userid != $USER->id) {
    $target_user = $DB->get_record('user', ['id' => $req_userid], '*', MUST_EXIST);
    $is_readonly = true;
}

$sesskey = sesskey();
$PAGE->set_url('/mod/ainotebook/view.php', array('id' => $id));
$PAGE->set_title(format_string($ainotebook->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');

echo $OUTPUT->header();

// Include FontAwesome and Custom CSS.
echo '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">';
echo '<link rel="stylesheet" href="styles.css?v=' . time() . '">';

// Include Marked.js and Mermaid.js.
echo '<script src="' . $CFG->wwwroot . '/mod/ainotebook/js/marked.min.js?v=' . time() . '"></script>';
echo '<script src="' . $CFG->wwwroot . '/mod/ainotebook/js/mermaid.min.js?v=' . time() . '"></script>';

// The teacher dashboard has been moved to report.php.

// Get files.
$fs = get_file_storage();
$files = $fs->get_area_files($context->id, 'mod_ainotebook', 'files', 0, 'id', false);

?>

<div id="ainotebook-wrapper">
    <?php if ($is_readonly): ?>
    <div class="readonly-banner">
        <div class="readonly-info">
            <i class="fa fa-eye"></i> 
            <span><strong><?php echo get_string('readonlymode', 'mod_ainotebook'); ?></strong> - <?php echo get_string('viewingprogressfor', 'mod_ainotebook'); ?> <?php echo fullname($target_user); ?> (<?php echo s($target_user->email); ?>)</span>
        </div>
        <a href="?id=<?php echo $cm->id; ?>" class="btn-premium btn-small"><i class="fa fa-arrow-left"></i> <?php echo get_string('backtodashboard', 'mod_ainotebook'); ?></a>
    </div>
    <?php elseif ($is_teacher && $viewself): ?>
    <div class="readonly-banner" style="background: #e0f2fe; border-color: #7dd3fc;">
        <div class="readonly-info" style="color: #0369a1;">
            <i class="fa fa-user"></i> 
            <span><strong>Personal Workspace</strong> - You are currently using your own AI Notebook.</span>
        </div>
        <a href="?id=<?php echo $cm->id; ?>" class="btn-premium btn-small"><i class="fa fa-arrow-left"></i> <?php echo get_string('backtodashboard', 'mod_ainotebook'); ?></a>
    </div>
    <?php endif; ?>

<div id="ainotebook-wrapper">
    <!-- Main Workspace Card: Materials + Chat -->
    <div class="main-dashboard-card">
        <!-- Sidebar Panel: Material List -->
        <div id="ainotebook-sidebar-nav" class="ainotebook-sidebar">
            <div class="sidebar-header">
                <h3><i class="fa fa-folder-open"></i> Materials</h3>
                <button id="toggle-sidebar" class="btn-icon"><i class="fa fa-angle-double-left"></i></button>
            </div>
            <div class="material-list">
                <div class="select-all-container">
                    <input type="checkbox" id="select-all-files" checked>
                    <label for="select-all-files">Select All</label>
                </div>
                <?php
                foreach ($files as $file) {
                    if ($file->is_directory()) continue;
                    $url = moodle_url::make_pluginfile_url($file->get_contextid(), $file->get_component(), $file->get_filearea(), $file->get_itemid(), $file->get_filepath(), $file->get_filename());
                    echo '<div class="material-file-item">';
                    echo '<input type="checkbox" class="file-checkbox" value="' . $file->get_id() . '" checked>';
                    echo '<div class="material-file">';
                    echo '<i class="fa fa-file-pdf-o"></i>';
                    echo '<a href="' . $url . '" target="_blank">' . s($file->get_filename()) . '</a>';
                    echo '</div>';
                    echo '</div>';
                }
                ?>
            </div>
        </div>

        <!-- Chat Panel: The AI Interface -->
        <div class="ainotebook-chat">
            <div class="chat-header">
                <div class="chat-brand-section">
                    <img src="<?php echo $CFG->wwwroot; ?>/mod/ainotebook/pix/icon.svg" class="chat-brand-icon">
                    <div class="chat-info">
                        <h2><?php echo s($ai_name); ?></h2>
                        <p class="chat-subtitle">Your AI Study Assistant by President University</p>
                    </div>
                </div>
                <button id="open-settings" class="btn-icon" title="Configure Chat"><i class="fa fa-cog"></i></button>
            </div>
            <div id="chat-messages" class="chat-messages">
                <div class="message ai">
                    Hi, <?php echo s($target_user->firstname); ?>! I am DEMI Tutor, your course study companion. I can help you with:<br>
                    - Explaining the lecture materials shared by your teacher.<br>
                    - Creating quizzes for your exam practice.<br>
                    - Summarizing long readings and complex modules.<br>
                    - Generating mindmaps to help you visualize key concepts.<br><br>
                    Are you ready to start? What are we studying together today?
                </div>
                <?php
                $history = $DB->get_records('ainotebook_chat', ['ainotebookid' => $ainotebook->id, 'userid' => $target_user->id], 'timecreated ASC');
                foreach ($history as $log) {
                    $clean_response = preg_replace('/```json-quiz[\s\S]*?```/', '', $log->response);
                    $clean_response = preg_replace('/```mermaid[\s\S]*?```/', '', $clean_response);
                    $clean_response = preg_replace('/\[REPORT_START\][\s\S]*?\[REPORT_END\]/', '', $clean_response);
                    $clean_response = trim($clean_response);
                    if (empty($clean_response)) $clean_response = "I have generated the requested material below.";

                    echo '<div class="message user">' . nl2br(s($log->message)) . '</div>';
                    echo '<div class="message ai">' . nl2br($clean_response) . '</div>';
                }
                ?>
            </div>
            <div class="input-wrapper-container">
                <div class="input-wrapper" <?php if($is_readonly) echo 'style="opacity: 0.6; pointer-events: none;"'; ?>>
                    <input type="text" id="chat-input" placeholder="<?php echo get_string('asksomething', 'mod_ainotebook'); ?>" <?php if($is_readonly) echo 'disabled'; ?>>
                    <span id="source-count" class="source-pill">0 sources</span>
                    <button id="send-btn" <?php if($is_readonly) echo 'disabled'; ?>>
                        <i class="fa fa-paper-plane"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Creator Hub Card: Separate at the bottom -->
    <div id="creator-hub" class="creator-hub-card" <?php if($is_readonly) echo 'style="pointer-events: none; opacity: 0.9;"'; ?>>
        <div class="creator-header-action" style="<?php if($is_readonly) echo 'pointer-events: auto;'; ?>">
            <h3>Learning Tools <?php if($is_readonly) echo '<span style="font-size: 0.8rem; font-weight: normal; color: var(--pres-text-dim);">(Read-Only)</span>'; ?></h3>
            <button id="toggle-creator" class="btn-icon" style="<?php if($is_readonly) echo 'pointer-events: auto;'; ?>"><i class="fa fa-angle-double-up"></i></button>
        </div>
        <div id="creator-hub-content" class="creator-content" style="<?php if($is_readonly) echo 'pointer-events: auto;'; ?>">
            <div class="creator-grid">
                <div class="creator-card quiz" onclick="window.sendSuggested('Generate a comprehensive quiz from my materials.', 'quiz')">
                    <div class="card-icon"><i class="fa fa-question-circle"></i></div>
                    <div class="card-info">
                        <h5>Quiz</h5>
                        <p>Practice your understanding using Interactive Quiz</p>
                    </div>
                </div>
                <div class="creator-card report" onclick="window.sendSuggested('Generate a detailed study report.', 'report')">
                    <div class="card-icon"><i class="fa fa-file-text-o"></i></div>
                    <div class="card-info">
                        <h5>Summary</h5>
                        <p>Get the summary of the learning materials.</p>
                    </div>
                </div>
                <div class="creator-card mindmap" onclick="window.sendSuggested('Generate a mindmap structure.', 'mindmap')">
                    <div class="card-icon"><i class="fa fa-sitemap"></i></div>
                    <div class="card-info">
                        <h5>Mindmap</h5>
                        <p>Generate a mindmap</p>
                    </div>
                </div>
            </div>

            <div class="creator-workspace">
                <div class="creator-history-panel">
                    <div class="panel-header">
                        <i class="fa fa-history"></i> Generated learning tools
                    </div>
                    <div id="history-list" class="history-list-items"></div>
                </div>
                <div id="creator-results" class="creator-preview-panel">
                    <div class="preview-toolbar">
                        <button id="download-result" class="btn-premium">
                            <i class="fa fa-download"></i> Export to PDF
                        </button>
                    </div>
                    <div id="results-content" class="preview-scroll-area">
                        <div class="empty-preview">
                            <i class="fa fa-magic fa-3x"></i>
                            <p>Select an item from history to preview</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php
$saved_artifacts = $DB->get_records('ainotebook_artifacts', ['ainotebookid' => $ainotebook->id, 'userid' => $target_user->id], 'timecreated DESC');
$saved_json = json_encode(array_values($saved_artifacts));

// INLINE JS INJECTION (BYPASSING AMD CACHE).
$js = <<<'JS'
(function() {
JS;
$js .= "    var cmid = " . $cm->id . ";\n";
$js .= "    var sesskey = '" . $sesskey . "';\n";
$js .= "    var wwwroot = '" . $CFG->wwwroot . "';\n";
$js .= "    var activityName = " . json_encode($ainotebook->name) . ";\n";

    // [DYNAMIC LOGO] Check for custom uploaded logo.
    $pdf_logo_url = '';
    $fs = get_file_storage();
    $context_system = context_system::instance();
    $logo_files = $fs->get_area_files($context_system->id, 'mod_ainotebook', 'pdf_logo', 0, 'itemid, filepath, filename', false);
    if ($logo_files) {
        $logo_file = reset($logo_files);
        $pdf_logo_url = moodle_url::make_pluginfile_url($logo_file->get_contextid(), $logo_file->get_component(), $logo_file->get_filearea(), $logo_file->get_itemid(), $logo_file->get_filepath(), $logo_file->get_filename())->out(false);
    } else {
        $pdf_logo_url = $CFG->wwwroot . '/mod/ainotebook/pix/presunivlogo.png';
    }

    $js .= "    var pdfLogoUrl = '" . $pdf_logo_url . "';\n";
    $js .= "    var studentName = '" . addslashes(fullname($target_user)) . "';\n";
    $js .= "    var studentId = '" . addslashes($target_user->idnumber ?: $target_user->id) . "';\n    var notebookName = '" . addslashes($ainotebook->name) . "';\n    var savedArtifacts = " . $saved_json . ";\n";
    $js .= "    var isReadonly = " . ($is_readonly ? 'true' : 'false') . ";\n";
$js .= <<<'JS'

    console.log("AI Notebook JS Loaded. CMID: " + cmid);

    if (typeof mermaid !== "undefined") {
        mermaid.initialize({
            startOnLoad: false,
            theme: "default",
            securityLevel: "loose",
            flowchart: { useMaxWidth: false, htmlLabels: true }
        });
    }

    // Global handlers available immediately.
    window.sendSuggested = function(text, toolType = null) {
        var input = document.getElementById("chat-input");
        if (input) {
            input.value = text;
            
            if (toolType) {
                var card = document.querySelector(".creator-card." + toolType);
                if (card) {
                    card.classList.add("loading");
                    var icon = card.querySelector(".card-icon i");
                    if (icon) icon.className = "fa fa-circle-o-notch fa-spin";
                }
                // Call sendMessage in silent mode.
                sendMessage(true);
            } else {
                var btn = document.getElementById("send-btn");
                if (btn) btn.click();
            }
        }
    };

    var initChat = function() {
        console.log("Initializing Chat UI...");
        var sendBtn = document.getElementById("send-btn");
        var input = document.getElementById("chat-input");
        var messages = document.getElementById("chat-messages");
        var resultsContainer = document.getElementById("creator-results");
        var resultsContent = document.getElementById("results-content");
        var downloadBtn = document.getElementById("download-result");

        if (!sendBtn || !input || !messages) return false;

        // Attach listeners early!
        sendBtn.onclick = function(e) {
            e.preventDefault();
            sendMessage();
        };

        input.onkeypress = function(e) {
            if (e.keyCode === 13) {
                e.preventDefault();
                sendMessage();
            }
        };

        if (typeof mermaid !== "undefined") {
            mermaid.initialize({ startOnLoad: false, theme: "base", securityLevel: "loose" });
        }

        var artifactHistory = [];

        var getIcon = function(type) {
            if (type === "quiz") return "fa-question-circle";
            if (type === "mindmap") return "fa-sitemap";
            if (type === "report") return "fa-file-text-o";
            return "fa-magic";
        };

        var updateHistoryList = function() {
            var list = document.getElementById("history-list");
            if (!list) return;
            if (artifactHistory.length === 0) {
                list.innerHTML = '<p class="no-history">No materials generated yet.</p>';
                return;
            }
            list.innerHTML = "";
            artifactHistory.slice().reverse().forEach((art, i) => {
                var realIdx = artifactHistory.length - 1 - i;
                var item = document.createElement("div");
                item.className = "history-item " + art.type + (art.saved ? " is-saved" : "");
                item.innerHTML = `
                    <div class="history-main" onclick="window.renderHistoryItem(${realIdx})">
                        <i class="fa ${getIcon(art.type)}"></i>
                        <span>${art.title}</span>
                    </div>
                    <div class="history-actions">
                        ${!art.saved && !isReadonly ? `<button title="Save to Database" onclick="window.saveArtifact(${realIdx})"><i class="fa fa-save"></i></button>` : `<span class="saved-badge"><i class="fa fa-check-circle"></i></span>`}
                        ${!isReadonly ? `<button title="Delete" onclick="window.deleteArtifact(${realIdx})"><i class="fa fa-trash"></i></button>` : ''}
                    </div>
                `;
                list.appendChild(item);
            });
        };
        // Load from DB.
        if (savedArtifacts && savedArtifacts.length > 0) {
            savedArtifacts.forEach(a => {
                artifactHistory.push({
                    dbid: a.id,
                    type: a.type,
                    data: JSON.parse(a.content),
                    title: a.title,
                    saved: true
                });
            });
            updateHistoryList();
        }

        // UI Handlers.
        var modal = document.getElementById("settings-modal");
        var openBtn = document.getElementById("open-settings");
        var closeBtn = document.getElementById("close-settings");
        var saveBtn = document.getElementById("save-settings");

        if (openBtn && modal) {
            openBtn.onclick = function() { modal.classList.add("active"); };
        }
        if (closeBtn && modal) {
            closeBtn.onclick = function() { modal.classList.remove("active"); };
        }
        if (saveBtn && modal) {
            saveBtn.onclick = function() {
                var config = {
                    style: document.getElementById("chat-style").value,
                    length: document.getElementById("chat-length").value
                };
                localStorage.setItem("ainotebook_config", JSON.stringify(config));
                modal.classList.remove("active");
                
                // Visual feedback.
                var originalText = saveBtn.innerHTML;
                saveBtn.innerHTML = "<i class='fa fa-check'></i> Saved!";
                setTimeout(() => { saveBtn.innerHTML = originalText; }, 2000);
            };
        }

        // Load existing config.
        var savedConfig = localStorage.getItem("ainotebook_config");
        if (savedConfig) {
            var config = JSON.parse(savedConfig);
            if (document.getElementById("chat-style")) document.getElementById("chat-style").value = config.style;
            if (document.getElementById("chat-length")) document.getElementById("chat-length").value = config.length;
        }
        
        var selectAll = document.getElementById("select-all-files");
        if (selectAll) {
            selectAll.onchange = function() {
                document.querySelectorAll(".file-checkbox").forEach(cb => cb.checked = selectAll.checked);
            };
        }

        var detectAndRenderArtifacts = function(text, type, addToHistory = true) {
            var found = false;
            var artifact = null;
            var cleanText = text;
            var artType = null;

            // 1. Check for QUIZ (Support both json-quiz and plain json tags).
            var quizMatch = text.match(/```(?:json-quiz|json)\s*([\s\S]*?)```/);
            if (quizMatch) {
                try {
                    var raw = quizMatch[1].trim();
                    var parsed = JSON.parse(raw);
                    var quizData = parsed.quiz || (parsed.questions ? parsed : null);
                    if (quizData && quizData.questions) {
                        artType = "quiz";
                        artifact = { type: "quiz", data: quizData, title: "Quiz - " + new Date().toLocaleTimeString() };
                        cleanText = text.replace(quizMatch[0], "").trim();
                        found = true;
                        console.log("Detected Quiz artifact.");
                    }
                } catch (e) {
                    console.error("Quiz JSON parse error:", e);
                }
            }

            // 2. Check for MINDMAP (Mermaid).
            if (!found) {
                var mermaidMatch = text.match(/```mermaid\s*([\s\S]*?)```/);
                if (mermaidMatch) {
                    artType = "mindmap";
                    artifact = { type: "mindmap", data: mermaidMatch[1].trim(), title: "Mindmap - " + new Date().toLocaleTimeString() };
                    cleanText = text.replace(mermaidMatch[0], "").trim();
                    found = true;
                    console.log("Detected Mindmap artifact.");
                }
            }

            if (!found && text.includes("[REPORT_START]")) {
                var reportMatch = text.match(/\[REPORT_START\]([\s\S]*?)\[REPORT_END\]/);
                if (reportMatch) {
                    artType = "report";
                    artifact = { type: "report", data: reportMatch[1], title: "Report - " + new Date().toLocaleTimeString() };
                    cleanText = text.replace(reportMatch[0], "").trim();
                    found = true;
                }
            }

            if (found && artifact) {
                if (addToHistory) {
                    artifactHistory.push(artifact);
                    updateHistoryList();
                }
                renderArtifact(artifact);
                return { found: true, type: artType, cleanText: cleanText };
            }

            return { found: false, cleanText: text };
        };


        window.renderHistoryItem = function(idx) {
            window.lastRenderedIdx = idx;
            renderArtifact(artifactHistory[idx]);
        };

        window.saveArtifact = function(idx) {
            var art = artifactHistory[idx];
            var formData = new FormData();
            formData.append("cmid", cmid);
            formData.append("sesskey", sesskey);
            formData.append("type", art.type);
            formData.append("title", art.title);
            formData.append("content", JSON.stringify(art.data));

            fetch(wwwroot + "/mod/ainotebook/save_artifact_ajax.php", {
                method: "POST",
                body: formData
            })
            .then(r => r.json())
            .then(d => {
                if (d.success) {
                    art.saved = true;
                    art.dbid = d.id;
                    updateHistoryList();
                    alert("Successfully saved to database!");
                } else alert("Error saving: " + d.error);
            });
        };

        window.deleteArtifact = function(idx) {
            var art = artifactHistory[idx];
            if (confirm("Delete this generated item?")) {
                if (art.saved && art.dbid) {
                    var formData = new FormData();
                    formData.append("artifactid", art.dbid);
                    formData.append("sesskey", sesskey);
                    fetch(wwwroot + "/mod/ainotebook/delete_artifact_ajax.php", {
                        method: "POST",
                        body: formData
                    })
                    .then(r => r.json())
                    .then(d => {
                        if (d.success) {
                            artifactHistory.splice(idx, 1);
                            updateHistoryList();
                        }
                    });
                } else {
                    artifactHistory.splice(idx, 1);
                    updateHistoryList();
                }
            }
        };

        var getIcon = function(type) {
            if (type === "quiz") return "fa-question-circle";
            if (type === "mindmap") return "fa-sitemap";
            if (type === "report") return "fa-file-text-o";
            return "fa-magic";
        };

        var setPrintLandscape = function(isLandscape) {
            var styleEl = document.getElementById("print-orientation-style");
            if (!styleEl) {
                styleEl = document.createElement("style");
                styleEl.id = "print-orientation-style";
                document.head.appendChild(styleEl);
            }
            if (isLandscape) {
                styleEl.innerHTML = "@media print { @page { size: landscape; margin: 0.5cm; } }";
            } else {
                styleEl.innerHTML = "";
            }
        };

        var renderArtifact = function(art) {
            window.lastRenderedIdx = artifactHistory.indexOf(art);
            if (art.type === "mindmap") {
                setPrintLandscape(true);
                renderMindmap(art.data);
            } else {
                setPrintLandscape(false);
                if (art.type === "quiz") renderQuiz(art.data);
                if (art.type === "report") renderReport(art.data);
            }
        };

        var renderQuiz = function(data) {
            resultsContainer.style.display = "block";
            resultsContent.innerHTML = "<div class=\'quiz-results-header\'><h3>Interactive Quiz</h3><div class=\'quiz-score\' id=\'quiz-score\'>Score: 0/" + data.questions.length + "</div></div><div id=\'quiz-container\'></div>";
            var container = document.getElementById("quiz-container");
            var currentIdx = 0;
            var score = 0;

            var showQuestion = function(idx) {
                container.innerHTML = "";
                var q = data.questions[idx];
                var qDiv = document.createElement("div");
                qDiv.className = "quiz-question active-question";
                
                // Normalize answer (handle 0-3, "0"-"3", or "A"-"E")
                var correctAnswer = q.answer;
                if (typeof correctAnswer === "string") {
                    var upper = correctAnswer.trim().toUpperCase();
                    if (upper.length === 1 && upper >= "A" && upper <= "E") {
                        correctAnswer = upper.charCodeAt(0) - 65;
                    } else {
                        correctAnswer = parseInt(upper);
                    }
                }
                
                var qText = q.text || q.question || "No question text provided.";
                qDiv.innerHTML = `<h5>Question ${idx+1} of ${data.questions.length}</h5>
                                 <p class="quiz-text">${qText}</p>
                                 <div class="quiz-options"></div>
                                 <div class="quiz-footer">
                                    <button class="btn-hint" onclick="var h=this.parentNode.querySelector('.quiz-hint'); if(h){h.style.display='block'; this.style.display='none';}"><i class="fa fa-lightbulb-o"></i> Show Hint</button>
                                    <div class="quiz-hint" style="display:none;"><strong>Hint:</strong> ${q.hint || "Try to recall the main concept from the study materials."}</div>
                                 </div>`;
                var optionsDiv = qDiv.querySelector(".quiz-options");
                var alpha = ["A", "B", "C", "D", "E"];
                q.options.forEach((opt, oi) => {
                    var optDiv = document.createElement("div");
                    optDiv.className = "quiz-option";
                    optDiv.innerHTML = `<span class="opt-label">${alpha[oi] || oi}</span> <span class="opt-text">${opt}</span>`;
                    optDiv.onclick = function() {
                        if (qDiv.classList.contains("answered")) return;
                        qDiv.classList.add("answered");
                        optDiv.classList.add("selected");
                        if (oi === correctAnswer) {
                            optDiv.classList.add("correct");
                            score++;
                        } else {
                            optDiv.classList.add("incorrect");
                            var allOpts = optionsDiv.querySelectorAll(".quiz-option");
                            if (allOpts[correctAnswer]) {
                                allOpts[correctAnswer].classList.add("correct");
                            }
                        }
                        document.getElementById("quiz-score").innerText = "Score: " + score + "/" + data.questions.length;
                        
                        var nextBtn = document.createElement("button");
                        nextBtn.className = "btn-premium btn-quiz-next";
                        nextBtn.innerHTML = (idx === data.questions.length - 1) ? "Finish & View Results" : "Next Question <i class=\'fa fa-chevron-right\'></i>";
                        nextBtn.onclick = function() {
                            if (idx < data.questions.length - 1) {
                                showQuestion(idx + 1);
                            } else {
                                var percent = (score / data.questions.length) * 100;
                                var feedback = "Keep Learning!";
                                var desc = "Every mistake is a step forward. Review the materials and try again to master the topic.";
                                if (percent >= 100) {
                                    feedback = "Excellent!";
                                    desc = "Perfect score! You have a solid understanding of the materials. Well done!";
                                } else if (percent >= 80) {
                                    feedback = "Great Job!";
                                    desc = "You've mastered most of the concepts. Just a few details left to polish!";
                                } else if (percent >= 60) {
                                    feedback = "Good Progress!";
                                    desc = "You're on the right track. A quick review of the material should get you to the top.";
                                }
                                
                                // Send score to Gradebook
                                var formData = new FormData();
                                formData.append("cmid", cmid);
                                formData.append("action", "submit_quiz_grade");
                                formData.append("score", score);
                                formData.append("maxscore", data.questions.length);
                                formData.append("sesskey", sesskey);
                                fetch(wwwroot + "/mod/ainotebook/chat_ajax.php?t=" + Date.now(), {
                                    method: "POST",
                                    body: formData
                                }).then(r => r.json()).then(res => {
                                    if(res.success) {
                                        var p = document.createElement("p");
                                        p.className = "gradebook-sync-msg";
                                        p.style.color = "#00d084";
                                        p.style.fontWeight = "bold";
                                        p.style.marginTop = "10px";
                                        p.innerHTML = "<i class=\'fa fa-check-circle\'></i> Score synchronized with Gradebook!";
                                        container.querySelector(".quiz-score-container").appendChild(p);
                                    }
                                });

                                container.innerHTML = `
                                    <div class="quiz-score-container">
                                        <div class="score-circle">
                                            <div class="score-value">${score}</div>
                                            <div class="score-max">/ ${data.questions.length}</div>
                                        </div>
                                        <div class="score-feedback">${feedback}</div>
                                        <p class="score-desc">${desc}</p>
                                        <div class="retake-action-area" style="display:flex; justify-content:center;"></div>
                                    </div>`;
                                
                                var retakeBtn = document.createElement("button");
                                retakeBtn.className = "btn-premium btn-retake";
                                retakeBtn.innerHTML = "<i class=\'fa fa-refresh\'></i> Retake Quiz";
                                retakeBtn.onclick = function() { renderQuiz(data); };
                                container.querySelector(".retake-action-area").appendChild(retakeBtn);
                            }
                        };
                        qDiv.appendChild(nextBtn);
                    };
                    optionsDiv.appendChild(optDiv);
                });
                container.appendChild(qDiv);
            };

            showQuestion(0);
            resultsContainer.scrollIntoView({ behavior: "smooth" });
            
            downloadBtn.innerHTML = "<i class=\'fa fa-download\'></i> Download Text";
            downloadBtn.onclick = function() {
                downloadFile("quiz.txt", data.questions.map((q,i) => (i+1) + ". " + (q.text || q.question || "No question") + "\n   " + q.options.join("\n   ")).join("\n\n"));
            };
        };

        var prepareFormalHeader = function(title) {
            var now = new Date().toLocaleDateString();
            return `
            <div class="pdf-cover-page">
                <div class="pdf-cover-logo">
                    <img src="${pdfLogoUrl}" class="pdf-brand-logo-large">
                    <div class="pdf-brand-text-large">
                        <h1 class="pdf-univ-title">PRESIDENT</h1>
                        <h1 class="pdf-univ-subtitle">UNIVERSITY</h1>
                    </div>
                </div>
                
                <div class="pdf-cover-main">
                    <h1 class="pdf-report-title">COMPREHENSIVE STUDY REPORT:<br/>${activityName.toUpperCase()}</h1>
                </div>

                <div class="pdf-cover-footer">
                    <div class="pdf-info-card">
                        <div class="info-row" style="font-weight: 800;">${studentName}</div>
                    </div>
                </div>
            </div>
            <div class="pdf-page-header">
                <div class="pdf-header-label">STUDY REPORT</div>
            </div>`;
        };

        // ── Mermaid syntax sanitizer (mirrors PHP-side sanitize_mermaid) ─────────
        var sanitizeMermaid = function(code) {
            var lines = code.trim().split("\n");
            var out = [];
            lines.forEach(function(line) {
                var t = line.trimEnd();
                // 1. Node labels with () must be quoted: A[X (Y)]  →  A["X (Y)"]
                t = t.replace(/([A-Za-z0-9_]+)\[([^\]"]*\([^\]]*\)[^\]"]*)\]/g, function(_, id, label) {
                    return id + '["' + label.replace(/"/g, "'") + '"]';
                });
                // 2. Stray > after pipe: -->|Label|>  →  -->|Label| 
                t = t.replace(/\|>\s*/g, "| ");
                // 3. Missing space before target: -->|Label|B[  →  -->|Label| B[
                t = t.replace(/(\|)([A-Za-z_][A-Za-z0-9_]*)\[/g, "$1 $2[");
                // 4. Split chained arrows: A[x] -->|y| B[z] -->|w| C  →  separate lines
                if ((t.match(/-->/g) || []).length > 1) {
                    // Split after ] or identifier when followed by another --> chain
                    var parts = t.split(/(?<=[\]A-Za-z0-9_])\s+(?=[A-Za-z0-9_]+(\s*-->|\s*\[))/);
                    if (parts.length > 1) {
                        parts.forEach(function(p) { if (p.trim()) out.push("    " + p.trim()); });
                        return;
                    }
                }
                // 5. Remove trailing connector with no target
                t = t.replace(/-->\s*\|[^|]+\|\s*$/, "");
                // 6. Remove duplicate arrows
                t = t.replace(/-->\s*-->/g, "-->");
                if (t.trim()) out.push(t);
            });
            return out.join("\n");
        };

        var renderMindmap = function(data) {
            resultsContainer.style.display = "block";
            var cleanData = sanitizeMermaid(data);

            resultsContent.innerHTML = prepareFormalHeader("Mindmap Visualization") + "<h3>Mindmap Concept</h3><div id='mermaid-container' class='mermaid'>" + cleanData + "</div>";
            try {
                mermaid.init(undefined, document.querySelectorAll(".mermaid"));
            } catch (err) {
                console.error("Mermaid init error:", err);
                document.getElementById("mermaid-container").innerHTML = "<div class='alert alert-warning'>Failed to render Mindmap. The AI generated invalid syntax. Please try again.</div>";
            }
            resultsContainer.scrollIntoView({ behavior: "smooth" });

            downloadBtn.innerHTML = "<i class='fa fa-file-pdf-o'></i> Export as PDF";
            downloadBtn.onclick = function() { window.print(); };
        };
        var renderReport = function(content) {
            resultsContainer.style.display = "block";
            resultsContent.innerHTML = prepareFormalHeader("Study Report") + "<div class=\'report-markdown\'>" + marked.parse(content) + "</div>";
            resultsContainer.scrollIntoView({ behavior: "smooth" });
            
            downloadBtn.innerHTML = "<i class=\'fa fa-file-pdf-o\'></i> Export as PDF";
            downloadBtn.onclick = function() {
                window.print();
            };
        };

        var downloadFile = function(filename, text) {
            var element = document.createElement("a");
            element.setAttribute("href", "data:text/plain;charset=utf-8," + encodeURIComponent(text));
            element.setAttribute("download", filename);
            element.style.display = "none";
            document.body.appendChild(element);
            element.click();
            document.body.removeChild(element);
        };

        var addMessage = function(text, type) {
            var msg = document.createElement("div");
            msg.className = "message " + type;
            if (typeof marked !== "undefined") {
                msg.innerHTML = marked.parse(text);
            } else {
                msg.innerHTML = text.replace(/\n/g, "<br>");
            }
            messages.appendChild(msg);
            messages.scrollTop = messages.scrollHeight;
        };

        // Format existing messages.
        document.querySelectorAll(".message.ai").forEach(function(el) {
            if (typeof marked !== "undefined" && !el.classList.contains("typing")) {
                el.innerHTML = marked.parse(el.innerText);
            }
        });

        window.sendMessage = function(silent = false) {
            var val = input.value.trim();
            if (val === "") return;

            // Auto-detect tool keywords in chat to trigger loading states (ONLY if not silent).
            if (!silent) {
                var lowerVal = val.toLowerCase();
                var toolType = null;
                if (lowerVal.includes("quiz")) toolType = "quiz";
                else if (lowerVal.includes("report")) toolType = "report";
                else if (lowerVal.includes("mindmap") || lowerVal.includes("mind map")) toolType = "mindmap";

                if (toolType) {
                    var card = document.querySelector(".creator-card." + toolType);
                    if (card) {
                        card.classList.add("loading");
                        var icon = card.querySelector(".card-icon i");
                        if (icon) icon.className = "fa fa-circle-o-notch fa-spin";
                    }
                }
                addMessage(val, "user");
            }

            input.value = "";
            input.style.height = "auto";
            
            var typing = null;
            var loadingInterval = null;
            if (!silent) {
                var loadingMessages = [
                    "DEMI TUTOR is exploring materials...",
                    "DEMI TUTOR is analyzing context...",
                    "DEMI TUTOR is synthesizing knowledge...",
                    "DEMI TUTOR is preparing your response..."
                ];
                var msgIdx = 0;
                
                typing = document.createElement("div");
                typing.className = "message ai typing";
                typing.innerHTML = "<i class=\'fa fa-circle-o-notch fa-spin\'></i> " + loadingMessages[0];
                messages.appendChild(typing);
                messages.scrollTop = messages.scrollHeight;

                loadingInterval = setInterval(function() {
                    msgIdx = (msgIdx + 1) % loadingMessages.length;
                    typing.innerHTML = "<i class=\'fa fa-circle-o-notch fa-spin\'></i> " + loadingMessages[msgIdx];
                }, 2000);
            }

            input.disabled = true;
            sendBtn.disabled = true;

            var selectedFiles = [];
            document.querySelectorAll(".file-checkbox:checked").forEach(function(cb) {
                selectedFiles.push(cb.value);
            });

            var formData = new FormData();
            formData.append("cmid", cmid);
            formData.append("message", val);
            formData.append("sesskey", sesskey);
            formData.append("selected_files", JSON.stringify(selectedFiles));
            formData.append("silent", silent ? 1 : 0);
            
            var savedConfig = localStorage.getItem("ainotebook_config") || "{}";
            formData.append("config", savedConfig);
            
            if (!silent) {
                formData.append("action", "chat_stream");
                
                fetch(wwwroot + "/mod/ainotebook/chat_ajax.php?t=" + Date.now(), {
                    method: "POST",
                    body: formData
                })
                .then(response => {
                    if (!response.ok) {
                        if (loadingInterval) clearInterval(loadingInterval);
                        if (typing && typing.parentNode) messages.removeChild(typing);
                        addMessage("Error: Server returned status " + response.status, "ai");
                        input.disabled = false;
                        sendBtn.disabled = false;
                        input.focus();
                        return;
                    }
                    if (!response.body) {
                        if (loadingInterval) clearInterval(loadingInterval);
                        if (typing && typing.parentNode) messages.removeChild(typing);
                        addMessage("Error: Browser does not support streaming.", "ai");
                        input.disabled = false;
                        sendBtn.disabled = false;
                        input.focus();
                        return;
                    }
                    
                    var aiMsgDiv = null;
                    var contentDiv = null;
                    
                    var reader = response.body.getReader();
                    var decoder = new TextDecoder();
                    var fullText = "";
                    var sourcesCount = 0;
                    var streamBuffer = "";
                    
                    function read() {
                        reader.read().then(function({done, value}) {
                            if (done) {
                                if (loadingInterval) clearInterval(loadingInterval);
                                if (typing && typing.parentNode) messages.removeChild(typing);
                                
                                input.disabled = false;
                                sendBtn.disabled = false;
                                input.focus();
                                
                                if (!aiMsgDiv) {
                                    addMessage("No response received from the AI.", "ai");
                                    return;
                                }
                                
                                var result = detectAndRenderArtifacts(fullText, "ai", true);
                                var displayMsg = result.cleanText;
                                if (result.found) {
                                    displayMsg += "\n\n*(I have also generated a " + result.type + " for you in the Creator section below)*";
                                }
                                if (sourcesCount > 0) {
                                    displayMsg += "\n\n<div class=\"context-source-badge\"><i class=\"fa fa-check-circle\"></i> Smart Context: Answer is generated using " + sourcesCount + " relevant material sources.</div>";
                                }
                                
                                contentDiv.innerHTML = window.marked.parse(displayMsg);
                                loadSuggestions();
                                return;
                            }
                            
                            streamBuffer += decoder.decode(value, {stream: true});
                            var pos;
                            while ((pos = streamBuffer.indexOf("\n")) !== -1) {
                                var line = streamBuffer.substring(0, pos).trim();
                                streamBuffer = streamBuffer.substring(pos + 1);
                                
                                if (line.indexOf("data: ") === 0) {
                                    try {
                                        var jsonStr = line.substring(6);
                                        var data = JSON.parse(jsonStr);
                                        if (data.chunk) {
                                            if (!aiMsgDiv) {
                                                if (loadingInterval) clearInterval(loadingInterval);
                                                if (typing && typing.parentNode) messages.removeChild(typing);
                                                
                                                aiMsgDiv = document.createElement("div");
                                                aiMsgDiv.className = "message ai";
                                                aiMsgDiv.innerHTML = "<div class='markdown-body'></div>";
                                                messages.appendChild(aiMsgDiv);
                                                contentDiv = aiMsgDiv.querySelector('.markdown-body');
                                            }
                                            
                                            fullText += data.chunk;
                                            // Render with a typing cursor block
                                            contentDiv.innerHTML = window.marked.parse(fullText + " ▊");
                                            messages.scrollTop = messages.scrollHeight;
                                        }
                                        if (data.sources_count !== undefined) {
                                            sourcesCount = data.sources_count;
                                        }
                                    } catch (e) {
                                        console.error("JSON parse error on line: ", line, e);
                                    }
                                }
                            }
                            read();
                        }).catch(function(error) {
                            console.error("Stream reading error:", error);
                            if (loadingInterval) clearInterval(loadingInterval);
                            if (typing && typing.parentNode) messages.removeChild(typing);
                            input.disabled = false;
                            sendBtn.disabled = false;
                            addMessage("Error reading AI stream.", "ai");
                        });
                    }
                    read();
                })
                .catch(error => {
                    console.error("Fetch error:", error);
                    if (loadingInterval) clearInterval(loadingInterval);
                    if (typing && typing.parentNode) messages.removeChild(typing);
                    input.disabled = false;
                    sendBtn.disabled = false;
                    input.focus();
                    addMessage("Network error or connection lost. Please try again.", "ai");
                });
            } else {
                fetch(wwwroot + "/mod/ainotebook/chat_ajax.php?t=" + Date.now(), {
                    method: "POST",
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    input.disabled = false;
                    sendBtn.disabled = false;
                    input.focus();

                    if (data.success) {
                        var result = detectAndRenderArtifacts(data.response, "ai", true);
                        if (!result.found) {
                            resultsContainer.style.display = "block";
                            resultsContent.innerHTML = "<div class='alert alert-warning text-center' style='margin-top:20px;'><i class='fa fa-exclamation-triangle fa-2x mb-2'></i><br><strong>Could not generate tool</strong><br>" + result.cleanText + "</div>";
                        }
                        
                        document.querySelectorAll(".creator-card.loading").forEach(card => {
                            card.classList.remove("loading");
                            var icon = card.querySelector(".card-icon i");
                            if (icon) {
                                if (card.classList.contains("quiz")) icon.className = "fa fa-question-circle fa-3x brand-color";
                                if (card.classList.contains("report")) icon.className = "fa fa-file-text-o fa-3x brand-success";
                                if (card.classList.contains("mindmap")) icon.className = "fa fa-sitemap fa-3x brand-info";
                            }
                        });
                    } else {
                        addMessage("Error: " + data.error, "ai");
                    }
                })
                .catch(error => {
                    if (loadingInterval) clearInterval(loadingInterval);
                    if (typing && typing.parentNode) messages.removeChild(typing);
                    input.disabled = false;
                    sendBtn.disabled = false;
                    var errMsg = "DEMI Tutor is currently assisting many students. Please wait a few moments and try your question again.";
                    if (!silent) {
                        addMessage(errMsg, "ai");
                    } else {
                        resultsContainer.style.display = "block";
                        resultsContent.innerHTML = "<div class='alert alert-danger text-center' style='margin-top:20px;'><i class='fa fa-exclamation-triangle fa-2x mb-2'></i><br><strong>Network Error</strong><br>" + errMsg + "</div>";
                    }
                    
                    // Clear all loading states on error as well.
                    document.querySelectorAll(".creator-card.loading").forEach(card => {
                        card.classList.remove("loading");
                        var icon = card.querySelector(".card-icon i");
                        if (icon) {
                            if (card.classList.contains("quiz")) icon.className = "fa fa-question-circle fa-3x brand-color";
                            if (card.classList.contains("report")) icon.className = "fa fa-file-text-o fa-3x brand-success";
                            if (card.classList.contains("mindmap")) icon.className = "fa fa-sitemap fa-3x brand-info";
                        }
                    });
                });
            }
        };

        var loadSuggestions = function() {
            var aiMessages = document.querySelectorAll(".message.ai");
            var lastAi = aiMessages[aiMessages.length - 1];
            if (!lastAi) return;

            // Remove previous smart suggestions to keep chat clean.
            document.querySelectorAll(".suggestion-container").forEach(el => el.remove());

            var container = document.createElement("div");
            container.className = "suggestion-container";
            container.innerHTML = "<div class='loading-suggestions'><i class='fa fa-circle-o-notch fa-spin'></i> Thinking...</div>";
            lastAi.parentNode.insertBefore(container, lastAi.nextSibling);

            var selectedFiles = [];
            document.querySelectorAll(".file-checkbox:checked").forEach(function(cb) {
                selectedFiles.push(cb.value);
            });

            var formData = new FormData();
            formData.append("cmid", cmid);
            formData.append("sesskey", sesskey);
            formData.append("selected_files", JSON.stringify(selectedFiles));

            fetch(wwwroot + "/mod/ainotebook/suggestions_ajax.php?t=" + Date.now(), {
                method: "POST",
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.suggestions && container) {
                    container.innerHTML = "";
                    data.suggestions.forEach(function(s) {
                        var btn = document.createElement("button");
                        btn.className = "suggestion-btn";
                        
                        // Suggestions are limited to 10 words by the AI/Backend.
                        btn.innerText = s;
                        btn.onclick = function() { sendSuggested(s); };
                        container.appendChild(btn);
                    });
                } else {
                    container.innerHTML = "";
                }
            })
            .catch(e => {
                console.error("Suggestions error:", e);
                container.innerHTML = "";
            });
        };

        // Suggestion loader.

        // AI Tool Buttons.
        document.querySelectorAll(".ai-tool-btn").forEach(btn => {
            btn.onclick = function() {
                const action = this.getAttribute("data-action");
                let promptText = "";
                if (action === "quiz") promptText = "Generate a quiz from the selected materials.";
                if (action === "report") promptText = "Generate a study report from the selected materials.";
                if (action === "mindmap") promptText = "Generate a mindmap structure for the selected materials.";
                
                if (promptText) {
                    input.value = promptText;
                    sendMessage(true); // Silent generation from Creator Hub.
                }
            };
        });

        // Sidebar Panel Toggle (Inside the main card).
        var sidebar = document.getElementById("ainotebook-sidebar-nav");
        var toggleBtn = document.getElementById("toggle-sidebar");
        if (toggleBtn && sidebar) {
            toggleBtn.onclick = function() {
                sidebar.classList.toggle("collapsed");
                var icon = toggleBtn.querySelector("i");
                icon.className = sidebar.classList.contains("collapsed") ? "fa fa-angle-double-right" : "fa fa-angle-double-left";
            };
        }

        // Creator Hub Card Toggle.
        var creatorToggle = document.getElementById("toggle-creator");
        var creatorContent = document.getElementById("creator-hub-content");
        if (creatorToggle && creatorContent) {
            creatorToggle.onclick = function() {
                creatorContent.classList.toggle("collapsed");
                var icon = creatorToggle.querySelector("i");
                icon.className = creatorContent.classList.contains("collapsed") ? "fa fa-angle-double-down" : "fa fa-angle-double-up";
            };
        }

        // Build file mapping for inline citations
        var fileUrls = {};
        document.querySelectorAll("#ainotebook-sidebar-nav .material-file-item a").forEach(function(a) {
            var filename = a.innerText.trim();
            fileUrls[filename] = a.getAttribute("href");
        });

        // Intercept inline citations (#citation-) clicks
        document.addEventListener("click", function(e) {
            var a = e.target.closest("a");
            if (a && a.getAttribute("href")) {
                var href = a.getAttribute("href");
                var hashIndex = href.indexOf("#citation-");
                if (hashIndex !== -1) {
                    e.preventDefault();
                    var citationStr = href.substring(hashIndex + 10); // 10 is length of "#citation-"
                    var pageIndex = citationStr.lastIndexOf("-page-");
                    if (pageIndex !== -1) {
                        var filename = decodeURIComponent(citationStr.substring(0, pageIndex));
                        var page = citationStr.substring(pageIndex + 6);
                        var baseUrl = fileUrls[filename];
                        if (baseUrl) {
                            window.open(baseUrl + "#page=" + page, "_blank");
                        } else {
                            // Fallback try in case name contains spaces or special characters
                            var matchedKey = Object.keys(fileUrls).find(k => k.toLowerCase() === filename.toLowerCase());
                            if (matchedKey && fileUrls[matchedKey]) {
                                window.open(fileUrls[matchedKey] + "#page=" + page, "_blank");
                            } else {
                                alert("File not found: " + filename);
                            }
                        }
                    }
                }
            }
        });

        // Settings Modal.
        var modal = document.getElementById("settings-modal");
        var openBtn = document.getElementById("open-settings");
        var closeBtn = document.getElementById("close-settings");
        var saveBtn = document.getElementById("save-settings");

        var resultsContainer = document.getElementById("creator-results");
        loadSuggestions();
        messages.scrollTop = messages.scrollHeight;
        
        var scanHistoryForArtifacts = function() {
            messages.querySelectorAll(".message.ai").forEach(msg => {
                detectAndRenderArtifacts(msg.innerText, "ai", true);
            });
            if (resultsContainer) resultsContainer.style.display = "none";
        };

        scanHistoryForArtifacts();
        // Source counter and Select All logic.
        var updateSourceCount = function() {
            var checkedCount = document.querySelectorAll(".file-checkbox:checked").length;
            var pill = document.getElementById("source-count");
            if (pill) {
                pill.innerText = checkedCount + (checkedCount === 1 ? " source" : " sources");
                pill.style.display = checkedCount > 0 ? "inline-block" : "none";
            }
        };

        var selectAll = document.getElementById("select-all-files");
        if (selectAll) {
            selectAll.onchange = function() {
                var checkboxes = document.querySelectorAll(".file-checkbox");
                checkboxes.forEach(function(cb) { cb.checked = selectAll.checked; });
                updateSourceCount();
            };
        }

        // Add event listeners to individual checkboxes.
        document.querySelectorAll(".file-checkbox").forEach(function(cb) {
            cb.onchange = updateSourceCount;
        });

        updateSourceCount();
        return true;
    };

    var attempts = 0;
    var timer = setInterval(function() {
        if (initChat() || attempts > 20) clearInterval(timer);
        attempts++;
    }, 300);
})();
JS;

$PAGE->requires->js_init_code($js);

echo '
<!-- Configuration Modal -->
<div id="settings-modal" class="ain-modal-overlay">
    <div class="ain-modal-content">
        <div class="ain-modal-header">
            <h3>Configure chat</h3>
            <button id="close-settings" class="btn-icon"><i class="fa fa-times"></i></button>
        </div>
        <div class="ain-modal-body">
            <p class="ain-modal-desc">Notebooks can be customised to help you achieve different goals: do research, help learn, show various perspectives or converse in a particular style and tone.</p>
            
            <div class="setting-group">
                <label>Define your conversational goal, style or role</label>
                <select id="chat-style" class="custom-select">
                    <option value="general">Best for general purpose research and brainstorming tasks.</option>
                    <option value="tutor">Professional Tutor - patient and encouraging.</option>
                    <option value="critic">Critical Thinker - challenges your assumptions.</option>
                </select>
            </div>

            <div class="setting-group">
                <label>Choose your response length</label>
                <select id="chat-length" class="custom-select">
                    <option value="short">Short & Concise</option>
                    <option value="medium" selected>Balanced</option>
                    <option value="long">Detailed & Comprehensive</option>
                </select>
            </div>
        </div>
        <div class="ain-modal-footer">
            <button id="save-settings" class="btn-premium">Save Settings</button>
        </div>
    </div>
</div>';

echo $OUTPUT->footer();
