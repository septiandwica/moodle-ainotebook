<?php
/**
 * @package    mod_ainotebook
 * @copyright  2026 Tateta (samastanuswantara.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

// Must be logged in and must be a site admin.
require_login();
if (isguestuser() || !isloggedin()) {
    print_error('noguest');
}
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$PAGE->set_url(new moodle_url('/mod/ainotebook/usage_report.php'));
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title('AI Notebook Global Usage Report');
$PAGE->set_heading('Global AI API Usage Report');

// Handle reset action
$resetuserid = optional_param('resetuserid', 0, PARAM_INT);
if ($resetuserid > 0) {
    require_sesskey();
    $DB->delete_records('ainotebook_api_logs', ['userid' => $resetuserid]);
    \core\notification::add('Usage limits reset successfully for user ID ' . $resetuserid, \core\notification::SUCCESS);
    redirect(new moodle_url('/mod/ainotebook/usage_report.php'));
}

echo $OUTPUT->header();

$dbman = $DB->get_manager();
if (!$dbman->table_exists('ainotebook_api_logs')) {
    echo $OUTPUT->notification('The ainotebook_api_logs table does not exist. Please run the Moodle upgrade script.', 'error');
    echo $OUTPUT->footer();
    exit;
}

$today_start = strtotime('today midnight');

// Query aggregated usage per user
$sql = "
    SELECT 
        l.userid,
        u.firstname,
        u.lastname,
        u.email,
        COUNT(l.id) AS total_requests,
        SUM(l.tokens) AS total_tokens,
        MAX(l.timecreated) AS last_active,
        SUM(CASE WHEN l.timecreated >= :today1 THEN 1 ELSE 0 END) AS today_requests,
        SUM(CASE WHEN l.timecreated >= :today2 THEN l.tokens ELSE 0 END) AS today_tokens
    FROM {ainotebook_api_logs} l
    JOIN {user} u ON l.userid = u.id
    GROUP BY l.userid, u.firstname, u.lastname, u.email
    ORDER BY total_tokens DESC
";

$records = $DB->get_records_sql($sql, ['today1' => $today_start, 'today2' => $today_start]);

echo html_writer::start_tag('div', ['class' => 'container-fluid py-4']);

echo html_writer::tag('h3', 'User API Consumption');
echo html_writer::tag('p', 'This dashboard shows estimated token consumption and API request counts across all AI Notebook activities. You can use the "Reset" button to instantly clear a user\'s rate-limiting history.');

if (empty($records)) {
    echo $OUTPUT->notification('No usage data recorded yet.', 'info');
} else {
    $table = new html_table();
    $table->head = [
        'User',
        'Email',
        'Requests (Today / Total)',
        'Tokens (Today / Total)',
        'Last Active',
        'Action'
    ];
    $table->data = [];

    $total_reqs = 0;
    $total_toks = 0;

    foreach ($records as $r) {
        $today_r = $r->today_requests ?? 0;
        $today_t = $r->today_tokens ?? 0;
        
        $total_reqs += $r->total_requests;
        $total_toks += $r->total_tokens;

        $reseturl = new moodle_url('/mod/ainotebook/usage_report.php', ['resetuserid' => $r->userid, 'sesskey' => sesskey()]);
        $resetbtn = html_writer::link($reseturl, 'Reset Usage', [
            'class' => 'btn btn-sm btn-outline-danger',
            'onclick' => 'return confirm("Are you sure you want to reset this user\'s usage logs? This will clear their current limits.");'
        ]);

        $table->data[] = [
            fullname($r),
            $r->email,
            "<strong>{$today_r}</strong> / {$r->total_requests}",
            "<strong>{$today_t}</strong> / {$r->total_tokens}",
            userdate($r->last_active),
            $resetbtn
        ];
    }
    
    // Add summary row at the bottom
    $table->data[] = [
        html_writer::tag('strong', 'TOTAL'),
        '',
        html_writer::tag('strong', $total_reqs),
        html_writer::tag('strong', $total_toks),
        '',
        ''
    ];

    echo html_writer::table($table);
}

echo html_writer::link(new moodle_url('/admin/settings.php', ['section' => 'modsettingainotebook']), 'Back to Settings', ['class' => 'btn btn-secondary mt-4']);

echo html_writer::end_tag('div');
echo $OUTPUT->footer();
