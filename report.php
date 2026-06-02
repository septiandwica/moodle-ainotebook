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

require_login($course, true, $cm);
$context = context_module::instance($cm->id);

// Ensure the user has permission to view the progress.
require_capability('mod/ainotebook:viewprogress', $context);

$PAGE->set_url('/mod/ainotebook/report.php', array('id' => $id));
$PAGE->set_title(format_string($ainotebook->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');

// Tell Moodle which secondary navigation tab is active.
$PAGE->set_secondary_active_tab('mod_ainotebook_report');

echo $OUTPUT->header();

// Include FontAwesome and Custom CSS.
echo '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">';
echo '<link rel="stylesheet" href="styles.css?v=' . time() . '">';

// --- RENDER DASHBOARD ---
$users = get_enrolled_users($context, 'mod/ainotebook:view');
$students = [];
$student_ids = [];
foreach ($users as $u) {
    if (!has_capability('mod/ainotebook:viewprogress', $context, $u)) {
        $u->chatcount = 0;
        $u->artifactcount = 0;
        $u->lastactive = 0;
        $students[$u->id] = $u;
        $student_ids[] = $u->id;
    }
}

if (!empty($student_ids)) {
    list($insql, $params) = $DB->get_in_or_equal($student_ids, SQL_PARAMS_NAMED, 'uid');
    $params['notebookid1'] = $ainotebook->id;
    $params['notebookid2'] = $ainotebook->id;
    $params['notebookid3'] = $ainotebook->id;
    
    $chats = $DB->get_records_sql("
        SELECT userid, COUNT(id) as cnt, MAX(timecreated) as max_time
        FROM {ainotebook_chat}
        WHERE ainotebookid = :notebookid1 AND userid $insql
        GROUP BY userid
    ", $params);
    
    $artifacts = $DB->get_records_sql("
        SELECT userid, COUNT(id) as cnt, MAX(timecreated) as max_time
        FROM {ainotebook_artifacts}
        WHERE ainotebookid = :notebookid2 AND userid $insql
        GROUP BY userid
    ", $params);
    
    foreach ($chats as $c) {
        if (isset($students[$c->userid])) {
            $students[$c->userid]->chatcount = $c->cnt;
            $students[$c->userid]->lastactive = $c->max_time;
        }
    }
    
    foreach ($artifacts as $a) {
        if (isset($students[$a->userid])) {
            $students[$a->userid]->artifactcount = $a->cnt;
            if (empty($students[$a->userid]->lastactive) || $a->max_time > $students[$a->userid]->lastactive) {
                $students[$a->userid]->lastactive = $a->max_time;
            }
        }
    }
    
    $evals = $DB->get_records_sql("
        SELECT userid, score, insight_json
        FROM {ainotebook_evals}
        WHERE ainotebookid = :notebookid3 AND userid $insql
    ", $params);
    foreach ($evals as $e) {
        if (isset($students[$e->userid])) {
            $students[$e->userid]->eval_score = $e->score;
            $students[$e->userid]->eval_insight = $e->insight_json;
        }
    }
}

$total_students = count($students);
$total_chats = array_sum(array_column($students, 'chatcount'));
$total_materials = array_sum(array_column($students, 'artifactcount'));

echo '<div id="ainotebook-wrapper">';
echo '<div class="teacher-dashboard-container">';
echo '<div class="dashboard-header-action">';
echo '<h2>' . get_string('studentprogress', 'mod_ainotebook') . '</h2>';
echo '<a href="view.php?id='.$cm->id.'" class="btn-premium"><i class="fa fa-user"></i> ' . get_string('switchtopersonal', 'mod_ainotebook') . '</a>';
echo '</div>';

echo '<div class="metric-cards-grid">';
echo '<div class="metric-card"><div class="metric-icon"><i class="fa fa-users"></i></div><div class="metric-info"><h4>'.get_string('totalstudents', 'mod_ainotebook').'</h4><p>'.$total_students.'</p></div></div>';
echo '<div class="metric-card"><div class="metric-icon"><i class="fa fa-comments"></i></div><div class="metric-info"><h4>'.get_string('totalchats', 'mod_ainotebook').'</h4><p>'.$total_chats.'</p></div></div>';
echo '<div class="metric-card"><div class="metric-icon"><i class="fa fa-file-text-o"></i></div><div class="metric-info"><h4>'.get_string('totalmaterials', 'mod_ainotebook').'</h4><p>'.$total_materials.'</p></div></div>';
echo '</div>';

echo '<div class="student-table-wrapper">';
echo '<div class="table-toolbar"><input type="text" id="search-students" placeholder="'.get_string('searchstudents', 'mod_ainotebook').'" class="custom-select" style="max-width: 300px;"></div>';
echo '<table class="student-table">';
echo '<thead><tr><th>'.get_string('studentname', 'mod_ainotebook').'</th><th>'.get_string('chatsent', 'mod_ainotebook').'</th><th>'.get_string('materialsgenerated', 'mod_ainotebook').'</th><th>'.get_string('lastactive', 'mod_ainotebook').'</th><th>'.get_string('aiscore', 'mod_ainotebook').'</th><th></th></tr></thead>';
echo '<tbody id="student-tbody">';
foreach ($students as $stu) {
    $avatar = $OUTPUT->user_picture($stu, array('size' => 35, 'link' => false));
    $last_active_str = $stu->lastactive ? userdate($stu->lastactive) : get_string('neveractive', 'mod_ainotebook');
    echo '<tr class="student-row">';
    echo '<td><div class="stu-info-cell">' . $avatar . '<div><strong>' . fullname($stu) . '</strong><br><span>' . $stu->email . '</span></div></div></td>';
    echo '<td><span class="badge-chats">' . $stu->chatcount . '</span></td>';
    echo '<td><span class="badge-artifacts">' . $stu->artifactcount . '</span></td>';
    echo '<td><span class="stu-lastactive">' . $last_active_str . '</span></td>';
    
    echo '<td>';
    if (empty($stu->lastactive)) {
        echo '<div style="font-size: 0.85rem; color: #64748b; margin-bottom: 5px;">Not accessed yet</div>';
        echo '<button class="btn-premium btn-small" style="background: #e2e8f0; color: #334155; border: 1px solid #cbd5e1;" onclick="window.notifyStudent('.$stu->id.', this)"><i class="fa fa-bell"></i> Send Notify</button>';
    } else if (isset($stu->eval_score)) {
        $insight_esc = htmlspecialchars($stu->eval_insight, ENT_QUOTES, 'UTF-8');
        echo '<div class="eval-score-display" id="eval-display-'.$stu->id.'">';
        echo '<span class="score-val-container" style="display: inline-flex; align-items: center; gap: 5px;">';
        echo '<strong class="score-text">' . $stu->eval_score . '</strong><span class="score-max" style="color: #64748b; font-size: 0.85rem;">/100</span>';
        echo ' <button class="btn-icon-inline" onclick="window.startEditGrade('.$stu->id.', '.$stu->eval_score.')" title="Override Grade" style="background:none; border:none; color:#1e40af; cursor:pointer; padding: 2px 4px; font-size: 0.9rem;"><i class="fa fa-pencil"></i></button>';
        echo '</span>';
        echo '<span class="score-edit-container" style="display:none; align-items: center; gap: 4px;">';
        echo '<input type="number" class="score-input" min="0" max="100" style="width: 55px; padding: 2px 4px; border-radius: 4px; border: 1px solid #cbd5e1; font-size: 0.85rem;" value="'.$stu->eval_score.'">';
        echo ' <button class="btn-premium btn-small" onclick="window.saveGradeOverride('.$stu->id.', this)" style="padding: 2px 6px; font-size: 0.8rem; background: #00d084; border-color: #00d084; color: white;"><i class="fa fa-check"></i></button>';
        echo ' <button class="btn-premium btn-small" onclick="window.cancelEditGrade('.$stu->id.')" style="padding: 2px 6px; font-size: 0.8rem; background: #ef4444; border-color: #ef4444; color: white;"><i class="fa fa-times"></i></button>';
        echo '</span>';
        echo '<br/>';
        echo '<button class="btn-premium btn-small" onclick="window.showInsightModal(`'.$insight_esc.'`)" style="margin-top: 4px;"><i class="fa fa-lightbulb-o"></i> '.get_string('viewinsight', 'mod_ainotebook').'</button>';
        echo ' <button class="btn-premium btn-small" onclick="window.evaluateStudent('.$stu->id.', this)" title="Re-evaluate Student" style="margin-top: 4px;"><i class="fa fa-refresh"></i></button>';
        echo '</div>';
    } else {
        echo '<div class="eval-score-display" id="eval-display-'.$stu->id.'">';
        echo '<span class="score-val-container" style="display: inline-flex; align-items: center; gap: 5px;">';
        echo '<button class="btn-premium btn-small eval-btn" id="eval-btn-'.$stu->id.'" onclick="window.evaluateStudent('.$stu->id.', this)"><i class="fa fa-magic"></i> '.get_string('evaluatestudent', 'mod_ainotebook').'</button>';
        echo ' <button class="btn-icon-inline" onclick="window.startEditGrade('.$stu->id.', 0)" title="Set Grade Manually" style="background:none; border:none; color:#1e40af; cursor:pointer; padding: 2px 4px; font-size: 0.9rem;"><i class="fa fa-pencil"></i></button>';
        echo '</span>';
        echo '<span class="score-edit-container" style="display:none; align-items: center; gap: 4px;">';
        echo '<input type="number" class="score-input" min="0" max="100" style="width: 55px; padding: 2px 4px; border-radius: 4px; border: 1px solid #cbd5e1; font-size: 0.85rem;" value="0">';
        echo ' <button class="btn-premium btn-small" onclick="window.saveGradeOverride('.$stu->id.', this)" style="padding: 2px 6px; font-size: 0.8rem; background: #00d084; border-color: #00d084; color: white;"><i class="fa fa-check"></i></button>';
        echo ' <button class="btn-premium btn-small" onclick="window.cancelEditGrade('.$stu->id.')" style="padding: 2px 6px; font-size: 0.8rem; background: #ef4444; border-color: #ef4444; color: white;"><i class="fa fa-times"></i></button>';
        echo '</span>';
        echo '</div>';
    }
    echo '</td>';
    
    echo '<td><a href="view.php?id='.$cm->id.'&userid='.$stu->id.'" class="btn-premium btn-small"><i class="fa fa-folder-open"></i> '.get_string('viewworkspace', 'mod_ainotebook').'</a></td>';
    echo '</tr>';
}
echo '</tbody></table></div>';

echo '</div></div>';

// Insight Modal HTML
echo '
<div id="insight-modal" class="insight-modal" style="display:none;">
    <div class="insight-modal-content">
        <div class="insight-modal-header">
            <h3><i class="fa fa-lightbulb-o"></i> '.get_string('aiinsight', 'mod_ainotebook').'</h3>
            <span class="close-modal" onclick="document.getElementById(\'insight-modal\').style.display=\'none\'">&times;</span>
        </div>
        <div class="insight-modal-body">
            <div class="insight-score-circle" id="insight-score-display">0</div>
            <h4 class="insight-section-title">'.get_string('understanding', 'mod_ainotebook').'</h4>
            <p id="insight-understanding"></p>
            <h4 class="insight-section-title">'.get_string('activitysummary', 'mod_ainotebook').'</h4>
            <p id="insight-activity"></p>
            <h4 class="insight-section-title">'.get_string('recommendation', 'mod_ainotebook').'</h4>
            <p id="insight-recommendation"></p>
        </div>
    </div>
</div>
';

echo '<script>
    document.getElementById("search-students").addEventListener("keyup", function() {
        var val = this.value.toLowerCase();
        document.querySelectorAll(".student-row").forEach(function(row) {
            var text = row.innerText.toLowerCase();
            row.style.display = text.includes(val) ? "" : "none";
        });
    });
    
    window.showInsightModal = function(insightJsonStr) {
        try {
            var data = JSON.parse(insightJsonStr);
            document.getElementById("insight-score-display").innerText = data.score;
            document.getElementById("insight-understanding").innerText = data.understanding;
            document.getElementById("insight-activity").innerText = data.activity_summary;
            document.getElementById("insight-recommendation").innerText = data.recommendation;
            document.getElementById("insight-modal").style.display = "flex";
        } catch (e) {
            alert("Error parsing insight data.");
        }
    };
    
    window.notifyStudent = function(userId, btnElement) {
        var originalHtml = btnElement.innerHTML;
        btnElement.disabled = true;
        btnElement.innerHTML = "<i class=\'fa fa-spinner fa-spin\'></i> Sending...";
        
        var fd = new FormData();
        fd.append("cmid", '.$cm->id.');
        fd.append("action", "notify_student");
        fd.append("userid", userId);
        fd.append("sesskey", "'.sesskey().'");
        
        fetch("chat_ajax.php", {
            method: "POST",
            body: fd
        }).then(r => r.json()).then(res => {
            if(res.success) {
                btnElement.innerHTML = "<i class=\'fa fa-check\'></i> Sent";
                btnElement.style.background = "#dcfce7";
                btnElement.style.color = "#166534";
                btnElement.style.borderColor = "#bbf7d0";
            } else {
                alert("Failed to send notification.");
                btnElement.disabled = false;
                btnElement.innerHTML = originalHtml;
            }
        }).catch(e => {
            alert("Network error.");
            btnElement.disabled = false;
            btnElement.innerHTML = originalHtml;
        });
    };
    
    window.updateEvalDisplay = function(userId, evaluation) {
        var el = document.getElementById("eval-display-" + userId);
        if (!el) return;
        
        var score = evaluation.score;
        var insightStr = JSON.stringify(evaluation);
        var escapedInsight = insightStr.replace(/`/g, "\\`").replace(/"/g, "&quot;");
        
        var html = "";
        html += "<span class=\'score-val-container\' style=\'display: inline-flex; align-items: center; gap: 5px;\'>";
        html += "<strong class=\'score-text\'>" + score + "</strong><span class=\'score-max\' style=\'color: #64748b; font-size: 0.85rem;\'>/100</span>";
        html += " <button class=\'btn-icon-inline\' onclick=\'window.startEditGrade(" + userId + ", " + score + ")\' title=\'Override Grade\' style=\'background:none; border:none; color:#1e40af; cursor:pointer; padding: 2px 4px; font-size: 0.9rem;\'><i class=\'fa fa-pencil\'></i></button>";
        html += "</span>";
        
        html += "<span class=\'score-edit-container\' style=\'display:none; align-items: center; gap: 4px;\'>";
        html += "<input type=\'number\' class=\'score-input\' min=\'0\' max=\'100\' style=\'width: 55px; padding: 2px 4px; border-radius: 4px; border: 1px solid #cbd5e1; font-size: 0.85rem;\' value=\'" + score + "\'>";
        html += " <button class=\'btn-premium btn-small\' onclick=\'window.saveGradeOverride(" + userId + ", this)\' style=\'padding: 2px 6px; font-size: 0.8rem; background: #00d084; border-color: #00d084; color: white;\'><i class=\'fa fa-check\'></i></button>";
        html += " <button class=\'btn-premium btn-small\' onclick=\'window.cancelEditGrade(" + userId + ")\' style=\'padding: 2px 6px; font-size: 0.8rem; background: #ef4444; border-color: #ef4444; color: white;\'><i class=\'fa fa-times\'></i></button>";
        html += "</span>";
        
        html += "<br/>";
        html += "<button class=\'btn-premium btn-small\' onclick=\'window.showInsightModal(`" + escapedInsight + "`)\' style=\'margin-top: 4px;\'><i class=\'fa fa-lightbulb-o\'></i> " + "'.get_string('viewinsight', 'mod_ainotebook').'" + "</button>";
        html += " <button class=\'btn-premium btn-small\' onclick=\'window.evaluateStudent(" + userId + ", this)\' title=\'Re-evaluate Student\' style=\'margin-top: 4px;\'><i class=\'fa fa-refresh\'></i></button>";
        
        el.innerHTML = html;
    };

    window.evaluateStudent = function(userId, btnElement) {
        var originalHtml = btnElement.innerHTML;
        btnElement.disabled = true;
        btnElement.innerHTML = "<i class=\'fa fa-spinner fa-spin\'></i> '.get_string('evaluating', 'mod_ainotebook').'";
        
        var fd = new FormData();
        fd.append("cmid", '.$cm->id.');
        fd.append("action", "evaluate_student");
        fd.append("userid", userId);
        fd.append("sesskey", "'.sesskey().'");
        
        fetch("chat_ajax.php", {
            method: "POST",
            body: fd
        }).then(r => r.json()).then(res => {
            if(res.success && res.evaluation) {
                window.updateEvalDisplay(userId, res.evaluation);
            } else {
                alert("Evaluation failed: " + (res.error || "Unknown error"));
                btnElement.disabled = false;
                btnElement.innerHTML = originalHtml;
            }
        }).catch(e => {
            alert("Network error.");
            btnElement.disabled = false;
            btnElement.innerHTML = originalHtml;
        });
    };

    window.startEditGrade = function(userId, currentScore) {
        var el = document.getElementById("eval-display-" + userId);
        if (el) {
            el.querySelector(".score-val-container").style.display = "none";
            el.querySelector(".score-edit-container").style.display = "inline-flex";
            var input = el.querySelector(".score-input");
            input.value = currentScore;
            input.focus();
        }
    };
    
    window.cancelEditGrade = function(userId) {
        var el = document.getElementById("eval-display-" + userId);
        if (el) {
            el.querySelector(".score-val-container").style.display = "inline-flex";
            el.querySelector(".score-edit-container").style.display = "none";
        }
    };
    
    window.saveGradeOverride = function(userId, btnElement) {
        var el = document.getElementById("eval-display-" + userId);
        if (!el) return;
        
        var input = el.querySelector(".score-input");
        var newScore = parseInt(input.value);
        if (isNaN(newScore) || newScore < 0 || newScore > 100) {
            alert("Please enter a valid score between 0 and 100.");
            return;
        }
        
        var originalHtml = btnElement.innerHTML;
        btnElement.disabled = true;
        btnElement.innerHTML = "<i class=\'fa fa-spinner fa-spin\'></i>";
        
        var fd = new FormData();
        fd.append("cmid", '.$cm->id.');
        fd.append("action", "override_grade");
        fd.append("userid", userId);
        fd.append("score", newScore);
        fd.append("sesskey", "'.sesskey().'");
        
        fetch("chat_ajax.php", {
            method: "POST",
            body: fd
        }).then(r => r.json()).then(res => {
            if(res.success && res.evaluation) {
                window.updateEvalDisplay(userId, res.evaluation);
            } else {
                alert("Failed to override grade.");
                btnElement.disabled = false;
                btnElement.innerHTML = originalHtml;
            }
        }).catch(e => {
            alert("Network error.");
            btnElement.disabled = false;
            btnElement.innerHTML = originalHtml;
        });
    };
</script>';

echo $OUTPUT->footer();
