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

defined('MOODLE_INTERNAL') || die();

use qtype_matrix\local\question_cleaner;
use qtype_matrix\local\question_matrix_store;

global $CFG;
require_once $CFG->dirroot . '/question/engine/bank.php';
require_once $CFG->dirroot . '/question/type/matrix/question.php';

/**
 * restore plugin class that provides the necessary information
 * needed to restore one match qtype plugin
 *
 */
class restore_qtype_matrix_plugin extends restore_qtype_plugin {

    /**
     * Return the contents of this qtype to be processed by the links decoder
     */
    public static function define_decode_contents(): array {
        $result = [];

        $fields = ['shorttext', 'description'];
        $result[] = new restore_decode_content('qtype_matrix_cols', $fields, 'qtype_matrix_cols');
        $fields = ['shorttext', 'description', 'feedback'];
        $result[] = new restore_decode_content('qtype_matrix_rows', $fields, 'qtype_matrix_rows');
        $fields = ['rowid', 'colid', 'weight'];
        $result[] = new restore_decode_content('qtype_matrix_weights', $fields, 'qtype_matrix_weights');

        return $result;
    }

    /**
     * Process the qtype/matrix
     *
     * @param $data
     * @return void
     * @throws dml_exception
     */
    public function process_matrix($data): void {
        global $DB;
        $data = (object) $data;
        $oldid = $data->id;

        // Todo: check import of version moodle1 data.

        if ($this->is_question_created()) {
            $qtypeobj = question_bank::get_qtype($this->pluginname);
            $data->{$qtypeobj->questionid_column_name()} = $this->get_new_parentid('question');
            $extrafields = $qtypeobj->extra_question_fields();
            $extrafieldstable = array_shift($extrafields);
            $newitemid = $DB->insert_record($extrafieldstable, $data);
            $this->set_mapping('matrix', $oldid, $newitemid);
        }
        else {
            $this->set_mapping('matrix', $oldid, null);
        }
    }

    /**
     * Detect if the question is created or mapped
     *
     * @return bool
     */
    protected function is_question_created(): bool {
        return (bool) $this->get_mappingid('question_created', $this->get_old_parentid('question'));
    }

    /**
     * Process the qtype/cols/col
     *
     * @param $data
     * @return void
     * @throws dml_exception
     */
    public function process_col($data): void {
        global $DB;
        $data = (object) $data;
        $oldid = $data->id;

        $oldmatrixid = $this->get_old_parentid('matrix');
        $newmatrixid = $this->get_new_parentid('matrix');
        if (!$newmatrixid) {
            return;
        }

        if ($this->is_question_created()) {
            $data->matrixid = $newmatrixid;
            $newitemid = $DB->insert_record('qtype_matrix_cols', $data);
        } else {
            $originalrecords = $DB->get_records('qtype_matrix_cols', ['matrixid' => $newmatrixid]);
            foreach ($originalrecords as $record) {
                if ($data->shorttext == $record->shorttext) { // Todo: this looks dirty to me!
                    $newitemid = $record->id;
                }
            }
        }
        if (!isset($newitemid)) {
            $info = new stdClass();
            $info->filequestionid = $oldmatrixid;
            $info->dbquestionid = $newmatrixid;
            $info->answer = $data->shorttext;
            throw new restore_step_exception('error_question_answers_missing_in_db', $info);
        } else {
            $this->set_mapping('col', $oldid, $newitemid);
        }
    }

    /**
     * Process the qtype/rows/row element
     *
     * @param $data
     * @return void
     * @throws dml_exception
     */
    public function process_row($data): void {
        global $DB;
        $data = (object) $data;
        $oldid = $data->id;

        $oldmatrixid = $this->get_old_parentid('matrix');
        $newmatrixid = $this->get_new_parentid('matrix');
        if (!$newmatrixid) {
            return;
        }

        if ($this->is_question_created()) {
            $data->matrixid = $newmatrixid;
            $newitemid = $DB->insert_record('qtype_matrix_rows', $data);
        } else {
            $originalrecords = $DB->get_records('qtype_matrix_rows', ['matrixid' => $newmatrixid]);
            foreach ($originalrecords as $record) {
                if ($data->shorttext == $record->shorttext) { // Todo: this looks dirty to me!
                    $newitemid = $record->id;
                }
            }
        }
        if (!$newitemid) {
            $info = new stdClass();
            $info->filequestionid = $oldmatrixid;
            $info->dbquestionid = $newmatrixid;
            $info->answer = $data->shorttext;
            throw new restore_step_exception('error_question_answers_missing_in_db', $info);
        } else {
            $this->set_mapping('row', $oldid, $newitemid);
        }
    }

    /**
     * Process the qtype/weights/weight element
     *
     * @param $data
     * @return void
     * @throws dml_exception
     */
    public function process_weight($data): void {
        global $DB;
        $data = (object) $data;
        $oldid = $data->id;

        $key = $data->colid . 'x' . $data->rowid;
        $data->colid = $this->get_mappingid('col', $data->colid);
        $data->rowid = $this->get_mappingid('row', $data->rowid);
        $newitemid = $DB->insert_record('qtype_matrix_weights', $data);
        $this->set_mapping('weight' . $key, $oldid, $newitemid);
    }

    // FIXME: This should be removed, as it should probably not be supported anymore (who even knows what this code should do?)
    /**
     * Map back
     *
     * @param $state
     * @return string
     */
    public function recode_legacy_state_answer($state): string {
        $result = [];
        $answer = unserialize($state->answer, ['allowed_classes' => false]);
        foreach ($answer as $rowid => $row) {
            $newrowid = $this->get_mappingid('row', $rowid);
            $newrow = [];
            foreach ($row as $colid => $cell) {
                $newcolid = $this->get_mappingid('col', $colid);
                $newrow[$newcolid] = $cell;
            }
            $result[$newrowid] = $newrow;
        }

        return serialize($result);
    }

    public function recode_response($questionid, $sequencenumber, array $response): array {
        $recodedresponse = [];
        $store = new question_matrix_store();
        $matrix = $store->get_matrix_by_question_id($questionid);
        $cols = $store->get_matrix_cols_by_matrix_id($matrix->id);
        $colids = array_keys($cols);

        foreach ($response as $key => $value) {
            if ($key == '_order') {
                $recodedresponse['_order'] = $this->recode_choice_order($value);
            } else if (str_contains($key, 'cell')) {
                // FIXME: Test this (look at the old data)
                // FIXME: Check...visualize
                $nocellkey = substr($key, strlen('cell'));
                $keyids = explode('_', $nocellkey);
                $oldrowid = $keyids[0];
                $newrowid = $this->get_mappingid('row', $oldrowid, 0);
                $newrowindex = array_search($newrowid, $recodedresponse['_order']);
                $oldcolid = $matrix->multiple ? $keyids[1] : $value;
                $newcolid = $this->get_mappingid('col', $oldcolid, 0);
                $newcolindex = array_search($newcolid, $colids);

                // At this point we either could map a backup ID to new rows/cols of a new question or to an already existing question.
                // If we couldn't, then the row/col IDs from the attempt point to those of another earlier question version
                // This version may or may not be in the backup and thus may or may not have been restored yet.
                // FIXME: This is probably OK for 99% of backups, but what if you restore a backup containing both versions
                //        and an attempt of the second version contains references to the first version's rows/cols?
                //        My guess: This will throw an exception here because the attempt has the order mapped but then fails to map the step data rows/cols
                if ($newrowindex !== false && $newcolindex !== false) {
                    $newkey = qtype_matrix_question::new_form_cell_name($newrowindex, $newcolindex, $matrix->multiple);
                    $newvalue = $matrix->multiple ? true : $newcolindex;
                    $recodedresponse[$newkey] = $newvalue;
                } else {
                    throw new restore_step_exception('error_qtype_matrix_attempt_step_data_not_migratable');
                }
            } else {
                $recodedresponse[$key] = $value;
            }
        }
        return $recodedresponse;
    }

    /**
     * Recode the choice order as stored in the response.
     *
     * @param string $order the original order.
     * @return string the recoded order.
     */
    protected function recode_choice_order(string $order): string {
        $neworder = [];
        foreach (explode(',', $order) as $id) {
            if ($newid = $this->get_mappingid('row', $id)) {
                $neworder[] = $newid;
            }
        }
        return implode(',', $neworder);
    }

    /**
     * Returns the paths to be handled by the plugin at question level
     */
    protected function define_question_plugin_structure(): array {
        $result = [];

        $elename = 'matrix';
        $elepath = $this->get_pathfor('/matrix'); // We used get_recommended_name() so this works.
        $result[] = new restore_path_element($elename, $elepath);

        $elename = 'col';
        $elepath = $this->get_pathfor('/matrix/cols/col'); // We used get_recommended_name() so this works.
        $result[] = new restore_path_element($elename, $elepath);

        $elename = 'row';
        $elepath = $this->get_pathfor('/matrix/rows/row'); // We used get_recommended_name() so this works.
        $result[] = new restore_path_element($elename, $elepath);

        $elename = 'weight';
        $elepath = $this->get_pathfor('/matrix/weights/weight'); // We used get_recommended_name() so this works.
        $result[] = new restore_path_element($elename, $elepath);

        return $result;
    }

    /**
     * Converts the backup data structure to the question data structure.
     * This is needed for question identity hash generation to work correctly.
     *
     * @param array $backupdata Data from the backup
     * @return stdClass The converted question data
     */
    public static function convert_backup_to_questiondata(array $backupdata): stdClass {
        $questiondata = parent::convert_backup_to_questiondata($backupdata);
        $questiondata = question_cleaner::clean_data($questiondata, true);
        // Add the matrix-specific options.
        if (isset($backupdata['plugin_qtype_matrix_question']['matrix'][0])) {
            $matrix = $backupdata['plugin_qtype_matrix_question']['matrix'][0];

            // Process rows to correct format
            $rowids = [];
            if (isset($matrix['rows']['row'])) {
                $rows = [];
                foreach ($matrix['rows']['row'] as $row) {
                    $description = $row['description'] ?? '';

                    $row['matrixid'] = $matrix['id'];
                    $row['description'] = [
                        'text' => $description,
                        'format' => FORMAT_HTML
                    ];

                    $feedback = $row['feedback'] ?? '';
                    $row['feedback'] = [
                        'text' => $feedback,
                        'format' => FORMAT_HTML
                    ];

                    $rows[$row['id']] = (object) $row;
                    $rowids[] = $row['id'];
                }
                $questiondata->options->rows = $rows;
            }

            // Process cols to correct format
            $columnids = [];
            if (isset($matrix['cols']['col'])) {
                $columns = [];
                foreach ($matrix['cols']['col'] as $column) {
                    $column['matrixid'] = $matrix['id'];

                    $description = $column['description'] ?? '';
                    $column['description'] = [
                        'text' => $description,
                        'format' => FORMAT_HTML
                    ];

                    $columns[$column['id']] = (object) $column;
                    $columnids[] = $column['id'];
                }
                $questiondata->options->cols = $columns;
            }

            /**
             * Return the weights in the format of rowid -> colid -> value
             */
            $weights = [];

            // prepare weights with 0 values as they are not present when their value is empty
            foreach ($rowids as $rowid) {
                foreach ($columnids as $columnid) {
                    $weights[$rowid][$columnid] = 0;
                }
            }

            if (isset($matrix['weights']['weight'])) {
                foreach ($matrix['weights']['weight'] as $weight) {
                    $weight = (object) $weight;
                    $weights[$weight->rowid][$weight->colid] = $weight->weight;
                }
            }
            $questiondata->options->weights = $weights;
        }

        return $questiondata;
    }

    /**
     * Remove excluded fields from the questiondata structure. We use this function to remove the
     * id and questionid fields for the weights, because they cannot be removed via the default
     * mechanism due to the two-dimensional array. Once this is done, we call the parent function
     * to remove the necessary fields.
     *
     * @param stdClass $questiondata
     * @param array $excludefields Paths to the fields to exclude.
     * @return stdClass The $questiondata with excluded fields removed.
     */
    public static function remove_excluded_question_data(stdClass $questiondata, array $excludefields = []): stdClass {
        unset($questiondata->hints);

        return parent::remove_excluded_question_data($questiondata, $excludefields);
    }

    #[\Override]
    public function define_excluded_identity_hash_fields(): array {
        return [
            '/options/cols/id',
            '/options/cols/matrixid',
            '/options/rows/id',
            '/options/rows/matrixid',
            '/options/weights/id',
            '/options/weights/rowid',
            '/options/weights/colid',
        ];
    }

}
