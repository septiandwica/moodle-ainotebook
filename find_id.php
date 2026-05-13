<?php
require_once(__DIR__ . '/../../config.php');
global $DB;
$sql = "SELECT id FROM {course_modules} WHERE module = (SELECT id FROM {modules} WHERE name = 'ainotebook') LIMIT 1";
$record = $DB->get_record_sql($sql);
if ($record) {
    echo "ID:" . $record->id;
} else {
    echo "NONE";
}
unlink(__FILE__);
