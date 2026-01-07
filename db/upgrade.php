<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

// This file keeps track of upgrades to
// the match qtype plugin
//
// Sometimes, changes between versions involve
// alterations to database structures and other
// major things that may break installations.
//
// The upgrade function in this file will attempt
// to perform all the necessary actions to upgrade
// your older installation to the current version.
//
// If there's something it cannot do itself, it
// will tell you what you need to do.
//
// The commands in here will all be database-neutral,
// using the methods of database_manager class
//
// Please do not forget to use upgrade_set_timeout()
// before any action that may take longer time to finish.

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once $CFG->dirroot  . '/question/type/matrix/questiontype.php';

use qtype_matrix\db\migration_utils;
use qtype_matrix\exception\broken_matrix_attempt_exception;

/**
 * @param int $oldversion
 * @return bool
 * @throws ddl_exception
 * @throws ddl_field_missing_exception
 * @throws ddl_table_missing_exception
 * @throws downgrade_exception
 * @throws upgrade_exception
 */
function xmldb_qtype_matrix_upgrade(int $oldversion): bool {
    global $DB;
    $dbman = $DB->get_manager();
    if ($oldversion < 2014040800) {
        // Define table matrix to be created.
        $table = new xmldb_table('question_matrix');
        // Adding fields to table matrix.
        $newfield = $table->add_field(
            'shuffleanswers',
            XMLDB_TYPE_INTEGER,
            '2',
            null,
            XMLDB_NOTNULL,
            null,
            (int) qtype_matrix::DEFAULT_SHUFFLEANSWERS
        );
        $dbman->add_field($table, $newfield);
        upgrade_plugin_savepoint(true, 2014040800, 'qtype', 'matrix');
    }

    if ($oldversion < 2015070100) {
        // Define table matrix to be created.
        $table = new xmldb_table('question_matrix');
        // Adding fields to table matrix.
        $newfield = $table->add_field(
            'use_dnd_ui',
            XMLDB_TYPE_INTEGER,
            '2',
            null,
            XMLDB_NOTNULL,
            null,
            (int) qtype_matrix::DEFAULT_USEDNDUI
        );
        $dbman->add_field($table, $newfield);
        upgrade_plugin_savepoint(true, 2015070100, 'qtype', 'matrix');
    }

    if ($oldversion < 2023010303) {
        // Rename tables and columns to match the coding guidelines.
        $table = new xmldb_table('question_matrix');
        $dbman->rename_table($table, 'qtype_matrix');

        $table = new xmldb_table('question_matrix_cols');
        $dbman->rename_table($table, 'qtype_matrix_cols');

        $table = new xmldb_table('question_matrix_rows');
        $dbman->rename_table($table, 'qtype_matrix_rows');

        $table = new xmldb_table('question_matrix_weights');
        $dbman->rename_table($table, 'qtype_matrix_weights');

        $table = new xmldb_table('qtype_matrix');
        // Rename the field use_dnd_ui to usedndui because direct working with this variable will be hard in php,
        // when the coding standard don't allow '_' in variable names.
        $newfield = $table->add_field(
            'use_dnd_ui',
            XMLDB_TYPE_INTEGER,
            '2',
            null,
            XMLDB_NOTNULL,
            null,
            (int) qtype_matrix::DEFAULT_USEDNDUI
        );
        $dbman->rename_field($table, $newfield, 'usedndui');

        upgrade_plugin_savepoint(true, 2023010303, 'qtype', 'matrix');
    }
    if ($oldversion < 2025093001) {
        // Drop the unused renderer option field
        $table = new xmldb_table('qtype_matrix');
        $rendererfield = new xmldb_field('renderer');
        if ($dbman->field_exists($table, $rendererfield)) {
            $dbman->drop_field($table, $rendererfield);
        }
        upgrade_plugin_savepoint(true, 2025093001, 'qtype', 'matrix');

    }
    if ($oldversion < 2025093002) {
        // Replace the non-unique index
        $table = new xmldb_table('qtype_matrix');
        $oldforeignindex = new xmldb_index('quesmatr_que_ix', XMLDB_INDEX_NOTUNIQUE, ['questionid']);
        if ($dbman->index_exists($table, $oldforeignindex)) {
            $dbman->drop_index($table, $oldforeignindex);
            $newuniqueindex = new xmldb_index('qtypmatr_que_uix', XMLDB_INDEX_UNIQUE, ['questionid']);
            $dbman->add_index($table, $newuniqueindex);
        }
        upgrade_plugin_savepoint(true, 2025093002, 'qtype', 'matrix');

    }

    if ($oldversion < 2025093004) {
        // This can be long running depending on how many step data there is.
        // Keep in mind that if running this via webserver, that one needs an appropriate timeout, too.
        core_php_time_limit::raise(0);
        $now = time();
        $transaction = $DB->start_delegated_transaction();
        $questionbatchsize = 1000;
        // Show a progress bar.
        $total = $DB->count_records('question', ['qtype' => 'matrix']);
        $pbar = new progress_bar('upgrade_qtype_matrix_stepdata_to_row', 500, true);
        $nrprocessedquestions = 0;
        while ($nrprocessedquestions < $total) {
            $pbar->update($nrprocessedquestions, $total, "Updating attempt data for qtype_matrix questions - $nrprocessedquestions/$total questions.");
            $questionids = $DB->get_records(
                'question', ['qtype' => 'matrix'], 'id ASC', 'id', $nrprocessedquestions, $questionbatchsize
            );
            $nrfoundquestionids = count($questionids);
            $nrprocessedquestions += $nrfoundquestionids;

            [$qinsql, $qidparams] = $DB->get_in_or_equal(array_keys($questionids));

            // Leave out matrix questions with broken data (missing col records)
            $colssql = "
                SELECT qmc.id as colid, qm.id as matrixid, q.id as questionid
                FROM {question} q
                LEFT JOIN {qtype_matrix} qm ON qm.questionid = q.id
                LEFT JOIN {qtype_matrix_cols} qmc ON qmc.matrixid = qm.id
                WHERE q.id ".$qinsql. " AND qmc.id IS NOT NULL ORDER BY q.id, qm.id, qmc.id ASC
            ";
            $matrixcols = $DB->get_records_sql($colssql, $qidparams);

            $matrixinfos = [];
            $sqlnamecase = "";
            $sqlvaluecase = "";

            foreach ($matrixcols as $matrixcol) {
                if (!$matrixcol->matrixid || !$matrixcol->colid) {
                    // broken question (no matrix or no col records)
                    continue;
                }
                if (!isset($matrixinfos[$matrixcol->questionid])) {
                    $matrixinfos[$matrixcol->questionid] = [];
                }
                $matrix = &$matrixinfos[$matrixcol->questionid];
                if (!isset($matrix['matrixid'])) {
                    $matrix['matrixid'] = $matrixcol->matrixid;
                }
                if (!isset($matrix['cols'])) {
                    $matrix['cols'] = [];
                }
                $matrix['cols'][] = $matrixcol->colid;
                if (!isset($matrix['attemptroworder'])) {
                    $matrix['attemptroworder'] = [];
                }
            }
            unset($matrixcols);

            $orderstepdatasql = "
                SELECT qasd.id as stepdataid, q.id as questionid, qa.id as attemptid, qas.id as stepid, qasd.name, qasd.value
                FROM {question} q
                JOIN {question_attempts} qa ON qa.questionid = q.id
                JOIN {question_attempt_steps} qas ON qas.questionattemptid = qa.id
                JOIN {question_attempt_step_data} qasd ON qasd.attemptstepid = qas.id
                WHERE q.id ".$qinsql. "
                AND qasd.name = '_order' ORDER BY qasd.name DESC, q.id ASC, qa.id ASC, qas.id ASC, qasd.id ASC 
            ";

            $orderdatarecords = $DB->get_records_sql($orderstepdatasql, $qidparams);
            foreach ($orderdatarecords as $orderdata) {
                if (!isset($matrixinfos[$orderdata->questionid])) {
                    continue;
                }
                $matrix = &$matrixinfos[$orderdata->questionid];
                $matrix['attemptroworder'][$orderdata->attemptid] = explode(',', $orderdata->value);
            }
            unset($orderdatarecords);

            $cellstepdatasql = "
                SELECT qasd.id as stepdataid, q.id as questionid, qa.id as attemptid, qas.id as stepid, qasd.name, qasd.value
                FROM {question} q
                JOIN {question_attempts} qa ON qa.questionid = q.id
                JOIN {question_attempt_steps} qas ON qas.questionattemptid = qa.id
                JOIN {question_attempt_step_data} qasd ON qasd.attemptstepid = qas.id
                WHERE q.id ".$qinsql. "
                AND qasd.name ~ '^cell' ORDER BY qasd.name DESC, q.id ASC, qa.id ASC, qas.id ASC, qasd.id ASC 
            ";
            $celldatarecords = $DB->get_records_sql($cellstepdatasql, $qidparams);
            $nrcelldata = count($celldatarecords);
            $celldatacount = 0;

            $celldatabatchsize = 10000;
            $celldatabatchcount = 0;

            $celldataids = [];

            foreach ($celldatarecords as $celldata) {
                $celldatacount++;
                if (!isset($matrixinfos[$celldata->questionid])) {
                    continue;
                }
                $matrix = &$matrixinfos[$celldata->questionid];
                try {
                    [$newname, $newvalue] = migration_utils::to_new_name_and_value(
                        $celldata->name,
                        $celldata->value,
                        $matrix['attemptroworder'][$celldata->attemptid],
                        $matrix['cols']
                    );
                    $when = " WHEN id = ".$celldata->stepdataid;
                    $sqlnamecase .= $when;
                    $sqlvaluecase .= $when;
                    $sqlnamecase .= " THEN '".$newname."'";
                    $sqlvaluecase .= " THEN '".$newvalue."'";
                    $celldataids[] = $celldata->stepdataid;
                    $celldatabatchcount++;
                } catch (broken_matrix_attempt_exception $e) {
                    // Step data and matrix data probably doesn't match anymore
                    continue;
                }
                if ($celldatabatchcount == $celldatabatchsize || $celldatacount == $nrcelldata) {
                    if ($celldataids) {
                        [$insql, $celldataparams] = $DB->get_in_or_equal($celldataids);
                        $updatesql = "UPDATE {question_attempt_step_data}";
                        $sqlnamecase = " SET name = CASE" . $sqlnamecase . " END";
                        $sqlvaluecase = ", value = CASE" . $sqlvaluecase . " END";
                        $updatesql .= $sqlnamecase . $sqlvaluecase;
                        $updatesql .= " WHERE id ".$insql;
                        $DB->execute($updatesql, $celldataparams);
                    }
                    // Reset loop vars.
                    $sqlnamecase = "";
                    $sqlvaluecase = "";
                    $celldatabatchcount = 0;
                    $celldataids = [];
                }
            }
        }
        $pbar->update($nrprocessedquestions, $total, "Done. Seconds: ".(time() - $now));
        $transaction->allow_commit();
    }
    die();
    return true;
}
