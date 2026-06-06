
    var cmid = 1;
    var sesskey = "1";
    var wwwroot = "1";
    var activityName = "1";
    var pdfLogoUrl = "1";
    var studentName = "1";
    var studentId = "1";
    var notebookName = "1";
    var savedArtifacts = [];
    var isReadonly = true;
    var isTeacher = true;

    console.log("AI Notebook JS Loaded. CMID: " + cmid);

    if (typeof mermaid !== "undefined") {
        mermaid.initialize({
            startOnLoad: false,
            theme: "default",
            securityLevel: "loose",
            flowchart: { useMaxWidth: false, htmlLabels: true }
        });
    }

    /* Global handlers available immediately. */
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
                /* Call sendMessage in silent mode. */
                sendMessage(true);
            } else {
                var btn = document.getElementById("send-btn");
                if (btn) btn.click();
            }
        }
    };

    window.openMoodleQuizGenerator = function() {
        var modal = document.getElementById("moodle-quiz-modal");
        if (modal) modal.classList.add("active");
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

        /* Attach listeners early! */
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
            if (type === "summary") return "fa-file-text-o";
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
                item.innerHTML = '<div class="history-main" onclick="window.renderHistoryItem(' + realIdx + ')">' +
                    '<i class="fa ' + getIcon(art.type) + '"></i>' +
                    '<span>' + art.title + '</span>' +
                '</div>' +
                '<div class="history-actions">' +
                    (!art.saved && !isReadonly ? '<button title="Save to Database" onclick="window.saveArtifact(' + realIdx + ')"><i class="fa fa-save"></i></button>' : '<span class="saved-badge"><i class="fa fa-check-circle"></i></span>') +
                    (!isReadonly ? '<button title="Delete" onclick="window.deleteArtifact(' + realIdx + ')"><i class="fa fa-trash"></i></button>' : '') +
                '</div>';
                list.appendChild(item);
            });
        };
        /* Load from DB. */
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

        /* UI Handlers. */
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
                
                /* Visual feedback. */
                var originalText = saveBtn.innerHTML;
                saveBtn.innerHTML = "<i class='fa fa-check'></i> Saved!";
                setTimeout(() => { saveBtn.innerHTML = originalText; }, 2000);
            };
        }

        /* Load existing config. */
        var savedConfig = localStorage.getItem("ainotebook_config");
        if (savedConfig) {
            var config = JSON.parse(savedConfig);
            if (document.getElementById("chat-style")) document.getElementById("chat-style").value = config.style;
            if (document.getElementById("chat-length")) document.getElementById("chat-length").value = config.length;
        }

        var mqModal = document.getElementById("moodle-quiz-modal");
        var mqCloseBtn = document.getElementById("close-moodle-quiz");
        var mqGenerateBtn = document.getElementById("generate-moodle-quiz");
        
        window.openQuizGenerator = function() {
            if (mqModal) mqModal.classList.add("active");
        };

        if (mqCloseBtn && mqModal) {
            mqCloseBtn.onclick = function() { mqModal.classList.remove("active"); };
        }
        if (mqGenerateBtn && mqModal) {
            mqGenerateBtn.onclick = function() {
                var count = parseInt(document.getElementById("mq-count").value) || 10;
                
                var diffMode = document.getElementById("mq-diff-mode").value;
                var diffStr = "Auto / Random";
                if (diffMode === "custom") {
                    var e = document.getElementById("mq-diff-easy").value || 0;
                    var m = document.getElementById("mq-diff-medium").value || 0;
                    var h = document.getElementById("mq-diff-hard").value || 0;
                    var x = document.getElementById("mq-diff-expert").value || 0;
                    diffStr = "Exactly " + e + " Easy, " + m + " Medium, " + h + " Hard, and " + x + " Expert questions.";
                }
                
                var typeMode = document.getElementById("mq-type-mode").value;
                var typeStr = "Mixed / Random";
                if (typeMode === "custom") {
                    var mc = document.getElementById("mq-type-mc").value || 0;
                    var tf = document.getElementById("mq-type-tf").value || 0;
                    var es = document.getElementById("mq-type-es").value || 0;
                    var sa = document.getElementById("mq-type-sa").value || 0;
                    typeStr = "Exactly " + mc + " Multiple Choice, " + tf + " True/False, " + es + " Essay, and " + sa + " Short Answer questions.";
                }

                mqModal.classList.remove("active");
                
                var prompt = "Generate a quiz with exactly " + count + " questions based on the materials.\n\n";
                prompt += "Difficulty Distribution: " + diffStr + "\n";
                prompt += "Question Type Distribution: " + typeStr + "\n\n";
                prompt += "CRITICAL INSTRUCTION: You MUST follow these exact numbers. Do not generate more or fewer questions than requested. Include 'type' property in the JSON for each question ('multichoice', 'truefalse', 'essay', or 'shortanswer').";
                
                window.sendSuggested(prompt, 'quiz');
            };
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

            // 3. Check for SUMMARY.
            if (!found) {
                var summaryMatch = text.match(/\[SUMMARY_START\]([\s\S]*?)\[SUMMARY_END\]/);
                if (summaryMatch) {
                    artType = "summary";
                    artifact = { type: "summary", data: summaryMatch[1].trim(), title: "Summary - " + new Date().toLocaleTimeString() };
                    cleanText = text.replace(summaryMatch[0], "").trim();
                    found = true;
                    console.log("Detected Summary artifact.");
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
                    require(['core/notification'], function(Notification) { Notification.addNotification({message: 'Successfully saved to database!', type: 'success'}); });
                } else require(['core/notification'], function(Notification) { Notification.addNotification({message: 'Error saving: ' + d.error, type: 'error'}); });
            });
        };

        window.deleteArtifact = function(idx) {
            var art = artifactHistory[idx];
            require(['core/notification'], function(Notification) {
                Notification.confirm("Delete Artifact", "Are you sure you want to delete this generated item?", "Delete", "Cancel", function() {
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
                });
            });
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
                if (art.type === "summary") renderSummary(art.data);
            }
        };

        var renderQuiz = function(data) {
            resultsContainer.style.display = "block";
            
            resultsContent.innerHTML = "<div class=\'quiz-results-header\'><h3>Interactive Quiz Preview</h3><div class=\'quiz-score\' id=\'quiz-score\'>Score: 0/" + data.questions.length + "</div></div><div id=\'quiz-container\'></div>";
            
            var convertBtnMarkup = "";
            if (isTeacher && !isReadonly) {
                convertBtnMarkup = '<button id="btn-convert-moodle-quiz" class="btn-premium" style="background: var(--pres-primary); margin-left: 10px;"><i class="fa fa-graduation-cap"></i> Convert to Moodle Quiz</button>';
            }
            
            downloadBtn.innerHTML = "<i class=\'fa fa-download\'></i> Download Text";
            downloadBtn.onclick = function() {
                downloadFile("quiz.txt", data.questions.map((q,i) => (i+1) + ". " + (q.text || q.question || "No question") + "\n   " + (q.options ? q.options.join("\n   ") : "")).join("\n\n"));
            };
            
            /* Re-render toolbar to include the convert button */
            var toolbar = document.querySelector(".preview-toolbar");
            if (toolbar) {
                /* Ensure we don't append multiple times if they switch tabs */
                var existingConvert = document.getElementById("btn-convert-moodle-quiz");
                if (existingConvert) existingConvert.remove();
                if (isTeacher && !isReadonly) {
                    toolbar.insertAdjacentHTML('beforeend', convertBtnMarkup);
                    
                    document.getElementById("btn-convert-moodle-quiz").onclick = function() {
                        var modal = document.getElementById("convert-quiz-modal");
                        if (!modal) return;
                        modal.classList.add("active");
                        
                        var closeBtn = document.getElementById("close-convert-quiz");
                        var confirmBtn = document.getElementById("confirm-convert-quiz");
                        
                        var btn = this;
                        
                        if (closeBtn) closeBtn.onclick = function() { modal.classList.remove("active"); };
                        
                        if (confirmBtn) {
                            confirmBtn.onclick = function() {
                                var qName = document.getElementById("cq-name").value;
                                var qIntro = document.getElementById("cq-intro").value;
                                
                                if (!qName) {
                                    require(['core/notification'], function(Notification) { Notification.addNotification({message: 'Quiz name is required!', type: 'error'}); });
                                    return;
                                }
                                
                                modal.classList.remove("active");
                                btn.innerHTML = "<i class='fa fa-spinner fa-spin'></i> Converting...";
                                btn.disabled = true;

                                var formData = new FormData();
                                formData.append("cmid", cmid);
                                formData.append("sesskey", sesskey);
                                formData.append("name", qName);
                                formData.append("intro", qIntro);
                                formData.append("quizdata", JSON.stringify(data));

                                fetch(wwwroot + "/mod/ainotebook/create_moodle_quiz_ajax.php", {
                                    method: "POST",
                                    body: formData
                                })
                                .then(function(r) { return r.json(); })
                                .then(function(res) {
                                    if (res.success && res.url) {
                                        window.location.href = res.url;
                                    } else {
                                        require(['core/notification'], function(Notification) { Notification.addNotification({message: 'Failed to convert quiz: ' + (res.error || 'Unknown error'), type: 'error'}); });
                                        btn.innerHTML = "<i class='fa fa-graduation-cap'></i> Convert to Moodle Quiz";
                                        btn.disabled = false;
                                    }
                                }).catch(function(e) {
                                    require(['core/notification'], function(Notification) { Notification.addNotification({message: 'Network error occurred.', type: 'error'}); });
                                    btn.innerHTML = "<i class='fa fa-graduation-cap'></i> Convert to Moodle Quiz";
                                    btn.disabled = false;
                                });
                            };
                        }
                    };
                }
            }

            var container = document.getElementById("quiz-container");
            var currentIdx = 0;
            var score = 0;

            var showQuestion = function(idx) {
                container.innerHTML = "";
                var q = data.questions[idx];
                var qDiv = document.createElement("div");
                qDiv.className = "quiz-question active-question";
                
                /* Normalize answer (handle 0-3, "0"-"3", or "A"-"E") */
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
                var type = (q.type || "multichoice").toLowerCase();
                
                qDiv.innerHTML = '<h5>Question ' + (idx+1) + ' of ' + data.questions.length + ' <span style="font-size:0.7em; color:#64748b; background:#f1f5f9; padding:2px 6px; border-radius:4px; margin-left:10px;">' + type + '</span></h5>' +
                                 '<p class="quiz-text">' + qText + '</p>' +
                                 '<div class="quiz-options"></div>' +
                                 '<div class="quiz-footer">' +
                                    '<button class="btn-hint" onclick="var h=this.parentNode.querySelector(\'.quiz-hint\'); if(h){h.style.display=\'block\'; this.style.display=\'none\';}"><i class="fa fa-lightbulb-o"></i> Show Hint</button>' +
                                    '<div class="quiz-hint" style="display:none;"><strong>Hint:</strong> ' + (q.hint || "Try to recall the main concept from the study materials.") + '</div>' +
                                 '</div>';
                var optionsDiv = qDiv.querySelector(".quiz-options");
                
                var handleNext = function() {
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
                            
                            /* Send score to Gradebook */
                            if (!isReadonly) {
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
                            }
                            
                            container.innerHTML = '<div class="quiz-score-container text-center">' +
                                '<div class="score-circle">' +
                                    '<svg viewBox="0 0 36 36" class="circular-chart">' +
                                        '<path class="circle-bg" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />' +
                                        '<path class="circle" stroke-dasharray="' + percent + ', 100" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />' +
                                    '</svg>' +
                                    '<div class="score-text">' + score + '/' + data.questions.length + '</div>' +
                                '</div>' +
                                '<h3>' + feedback + '</h3>' +
                                '<p class="text-muted" style="margin-bottom:20px;">' + desc + '</p>' +
                                '<button class="btn-outline" onclick="window.renderHistoryItem(window.lastRenderedIdx)"><i class="fa fa-refresh"></i> Retake Quiz</button>' +
                            '</div>';
                        }
                    };
                    qDiv.appendChild(nextBtn);
                };

                if (type === "essay" || type === "shortanswer") {
                    var inputEl = type === "essay" ? document.createElement("textarea") : document.createElement("input");
                    inputEl.className = "form-control";
                    inputEl.style.marginBottom = "15px";
                    if (type === "essay") {
                        inputEl.rows = 4;
                        inputEl.placeholder = "Type your essay answer here...";
                    } else {
                        inputEl.type = "text";
                        inputEl.placeholder = "Type your short answer here...";
                    }
                    optionsDiv.appendChild(inputEl);
                    
                    var submitBtn = document.createElement("button");
                    submitBtn.className = "btn-premium";
                    submitBtn.innerHTML = "Submit Answer";
                    submitBtn.onclick = function() {
                        if (qDiv.classList.contains("answered")) return;
                        qDiv.classList.add("answered");
                        inputEl.disabled = true;
                        submitBtn.style.display = "none";
                        
                        var feedbackDiv = document.createElement("div");
                        feedbackDiv.className = "quiz-option correct";
                        feedbackDiv.style.marginTop = "10px";
                        feedbackDiv.innerHTML = "<strong>Suggested Answer/Rubric:</strong><br>" + (q.answer || "No specific answer provided by AI.");
                        optionsDiv.appendChild(feedbackDiv);
                        
                        /* Treat as correct for participation */
                        score++;
                        document.getElementById("quiz-score").innerText = "Score: " + score + "/" + data.questions.length;
                        handleNext();
                    };
                    optionsDiv.appendChild(submitBtn);
                } else {
                    // Multiple Choice / True False
                    var alpha = ["A", "B", "C", "D", "E", "F"];
                    var opts = q.options || (type === "truefalse" ? ["True", "False"] : []);
                    
                    opts.forEach((opt, oi) => {
                        var optDiv = document.createElement("div");
                        optDiv.className = "quiz-option";
                        optDiv.innerHTML = '<span class="opt-label">' + (alpha[oi] || oi) + '</span> <span class="opt-text">' + opt + '</span>';
                        optDiv.onclick = function() {
                            if (qDiv.classList.contains("answered")) return;
                            qDiv.classList.add("answered");
                            optDiv.classList.add("selected");
                            
                            // Check answer
                            var isCorrect = false;
                            if (type === "truefalse") {
                                var ansStr = String(correctAnswer).toLowerCase();
                                var chosenStr = opt.toLowerCase();
                                if (ansStr === chosenStr || (ansStr === "1" && chosenStr === "true") || (ansStr === "0" && chosenStr === "false")) {
                                    isCorrect = true;
                                }
                            } else {
                                if (oi === correctAnswer) isCorrect = true;
                            }
                            
                            if (isCorrect) {
                                optDiv.classList.add("correct");
                                score++;
                            } else {
                                optDiv.classList.add("incorrect");
                                var allOpts = optionsDiv.querySelectorAll(".quiz-option");
                                if (type === "truefalse") {
                                    // Find correct opt
                                    var correctIdx = String(correctAnswer).toLowerCase() === "false" || String(correctAnswer) === "0" ? 1 : 0;
                                    if (allOpts[correctIdx]) allOpts[correctIdx].classList.add("correct");
                                } else {
                                    if (allOpts[correctAnswer]) {
                                        allOpts[correctAnswer].classList.add("correct");
                                    }
                                }
                            }
                            document.getElementById("quiz-score").innerText = "Score: " + score + "/" + data.questions.length;
                            handleNext();
                        };
                        optionsDiv.appendChild(optDiv);
                    });
                }
                container.appendChild(qDiv);
            };

            showQuestion(0);
            resultsContainer.scrollIntoView({ behavior: "smooth" });
        };

        var prepareFormalHeader = function(title) {
            var now = new Date().toLocaleDateString();
            return '<div class="pdf-cover-page">' +
                '<div class="pdf-cover-logo">' +
                    '<img src="' + pdfLogoUrl + '" class="pdf-brand-logo-large">' +
                    '<div class="pdf-brand-text-large">' +
                        '<h1 class="pdf-univ-title">PRESIDENT</h1>' +
                        '<h1 class="pdf-univ-subtitle">UNIVERSITY</h1>' +
                    '</div>' +
                '</div>' +
                '<div class="pdf-cover-main">' +
                    '<h1 class="pdf-report-title">' + (title ? title.toUpperCase() : '') + ':<br/>' + (activityName ? activityName.toUpperCase() : '') + '</h1>' +
                '</div>' +
                '<div class="pdf-cover-footer">' +
                    '<div class="pdf-info-card">' +
                        '<div class="info-row" style="font-weight: 800;">' + studentName + '</div>' +
                    '</div>' +
                '</div>' +
            '</div>' +
            '<div class="pdf-page-header">' +
                '<div class="pdf-header-label">' + (title ? title.toUpperCase() : '') + '</div>' +
            '</div>';
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
        var renderSummary = function(content) {
            resultsContainer.style.display = "block";
            resultsContent.innerHTML = prepareFormalHeader("Study Summary") + "<div class=\'report-markdown\'>" + marked.parse(content) + "</div>";
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
                else if (lowerVal.includes("summary")) toolType = "summary";
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
                    var displayedText = "";
                    var sourcesCount = 0;
                    var streamBuffer = "";
                    
                    var typeInterval = setInterval(function() {
                        if (contentDiv && displayedText.length < fullText.length) {
                            var charsToAdd = Math.max(1, Math.floor((fullText.length - displayedText.length) / 3));
                            displayedText += fullText.substring(displayedText.length, displayedText.length + charsToAdd);
                            contentDiv.innerHTML = window.marked.parse(displayedText + " ▊");
                            messages.scrollTop = messages.scrollHeight;
                        }
                    }, 30);
                    
                    function read() {
                        reader.read().then(function({done, value}) {
                            if (done) {
                                clearInterval(typeInterval);
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
                                
                                // Extract suggestions tag from AI response
                                var sugMatch = displayMsg.match(/<suggestions>([\s\S]*?)<\/suggestions>/i);
                                var extractedSuggestions = [];
                                if (sugMatch) {
                                    var suggestionsStr = sugMatch[1];
                                    extractedSuggestions = suggestionsStr.split('|').map(function(s) { return s.trim(); }).filter(function(s) { return s.length > 0; });
                                    displayMsg = displayMsg.replace(sugMatch[0], "").trim();
                                }
                                
                                if (result.found) {
                                    displayMsg += "\n\n*(I have also generated a " + result.type + " for you in the Creator section below)*";
                                }
                                if (sourcesCount > 0) {
                                    displayMsg += "\n\n<div class=\"context-source-badge\"><i class=\"fa fa-check-circle\"></i> Smart Context: Answer is generated using " + sourcesCount + " relevant material sources.</div>";
                                }
                                
                                contentDiv.innerHTML = window.marked.parse(displayMsg);
                                
                                if (extractedSuggestions.length > 0) {
                                    renderSuggestions(extractedSuggestions, aiMsgDiv);
                                }
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
                            clearInterval(typeInterval);
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
                                if (card.classList.contains("summary")) icon.className = "fa fa-file-text-o fa-3x brand-success";
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

        function renderSuggestions(suggestionsArray, parentMsgNode) {
            // Remove existing container if any
            var existingContainer = document.querySelector(".suggestion-container");
            if (existingContainer) existingContainer.parentNode.removeChild(existingContainer);

            if (!suggestionsArray || suggestionsArray.length === 0) return;

            var container = document.createElement("div");
            container.className = "suggestion-container";
            
            suggestionsArray.forEach(function(s) {
                var btn = document.createElement("button");
                btn.className = "suggestion-btn";
                btn.innerText = s;
                btn.onclick = function() { sendSuggested(s); };
                container.appendChild(btn);
            });
            
            if (parentMsgNode && parentMsgNode.parentNode) {
                parentMsgNode.parentNode.insertBefore(container, parentMsgNode.nextSibling);
            } else {
                messages.appendChild(container);
            }
            if (typeof messages !== 'undefined' && messages) {
                messages.scrollTop = messages.scrollHeight;
            }
        }

        // Suggestion loader.

        // AI Tool Buttons.
        document.querySelectorAll(".ai-tool-btn").forEach(btn => {
            btn.onclick = function() {
                const action = this.getAttribute("data-action");
                let promptText = "";
                if (action === "quiz") promptText = "Generate a quiz from the selected materials.";
                if (action === "summary") promptText = "Generate a study summary from the selected materials.";
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
                                require(['core/notification'], function(Notification) { Notification.addNotification({message: 'File not found: ' + filename, type: 'error'}); });
                            }
                        }
                    } else {
                        var filename = decodeURIComponent(citationStr);
                        var baseUrl = fileUrls[filename];
                        if (baseUrl) {
                            window.open(baseUrl, "_blank");
                        } else {
                            var matchedKey = Object.keys(fileUrls).find(k => k.toLowerCase() === filename.toLowerCase());
                            if (matchedKey && fileUrls[matchedKey]) {
                                window.open(fileUrls[matchedKey], "_blank");
                            } else {
                                require(['core/notification'], function(Notification) { Notification.addNotification({message: 'File not found: ' + filename, type: 'error'}); });
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
        // Initial suggestions load removed to save API calls. Suggestions appear after chat.
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

