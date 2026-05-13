<?php
/**
 * @package    mod_ainotebook
 * @copyright  2024 Tateta
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade the ainotebook module.
 *
 * @param int $oldversion the version we are upgrading from.
 * @return bool result
 */
function xmldb_ainotebook_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2024051300) {

        // Define table ainotebook_artifacts to be created.
        $table = new xmldb_table('ainotebook_artifacts');

        // Adding fields to table ainotebook_artifacts.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('ainotebookid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('type', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('content', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        // Adding keys to table ainotebook_artifacts.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, array('id'));
        $table->add_key('ainotebookid', XMLDB_KEY_FOREIGN, array('ainotebookid'), 'ainotebook', array('id'));

        // Adding indexes to table ainotebook_artifacts.
        $table->add_index('userid', XMLDB_INDEX_NOTUNIQUE, array('userid'));

        // Conditionally create table for ainotebook_artifacts.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Ainotebook savepoint reached.
        upgrade_mod_savepoint(true, 2024051300, 'ainotebook');
    }

    return true;
}
