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
require_once($CFG->dirroot . '/question/engine/bank.php');

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
        $oldmatrixid = $data->id;

        // Todo: check import of version moodle1 data.

        if ($this->is_question_created()) {
            // If a new question record exists, we have to adapt the backup data to that new question
            $qtypeobj = question_bank::get_qtype($this->pluginname);
            $data->{$qtypeobj->questionid_column_name()} = $this->get_new_parentid('question');
            $extrafields = $qtypeobj->extra_question_fields();
            $extrafieldstable = array_shift($extrafields);
            $newmatrixid = $DB->insert_record($extrafieldstable, $data);
            $this->set_mapping('matrix', $oldmatrixid, $newmatrixid);
        }
        else {
            $existingquestionid = $this->get_new_parentid('question');
            $store = new question_matrix_store();
            $existingmatrix = $store->get_matrix_by_question_id($existingquestionid);
            if ($existingmatrix) {
                $this->set_mapping('matrix', $oldmatrixid, $existingmatrix->id);
            } else {
                throw new restore_step_exception('error_qtype_matrix_missing_matrix_record');
            }
        }
    }

    /**
     * Detect if a new question record was created
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
        $this->process_row_or_col($data, false);
    }

    /**
     * Process the qtype/rows/row element
     *
     * @param $data
     * @return void
     * @throws dml_exception
     */
    public function process_row($data): void {
        $this->process_row_or_col($data, true);
    }

    /**
     * Process dimensional records.
     * @param $data
     * @param bool $isrow
     * @return void
     * @throws dml_exception
     * @throws restore_step_exception
     */
    private function process_row_or_col($data, bool $isrow) {
        global $DB;
        $dim = $isrow ? 'row' : 'col';
        $dimtable = 'qtype_matrix_'.$dim.'s';

        $data = (object) $data;
        $olddimid = $data->id;

        $newmatrixid = $this->get_new_parentid('matrix');

        if ($this->is_question_created()) {
            $data->matrixid = $newmatrixid;
            $newdimid = $DB->insert_record($dimtable, $data);
        } else {
            // Can't use the dim id here because a different version of the question could have the identical hash
            // But the new matrix id here is always the id of an existing matrix found earlier
            // TODO: This is still problematic because somehow each item could have the same shorttext and description (even if that is nonsensical)
            // TODO: Maybe the combination of matrixid and shorttext should be unique?
            $params = [
                'matrixid' => $newmatrixid,
            ];
            $existingdims = $DB->get_records($dimtable, $params);
            foreach ($existingdims as $existingdim) {
                if (
                    $existingdim->shorttext != $data->shorttext
                    || $existingdim->description != $data->description
                ) {
                    continue;
                }
                if ($isrow && $existingdim->feedback != $data->feedback) {
                    continue;
                }
                $matchingdim = $existingdim;
                break;
            }
            $newdimid = $matchingdim->id ?? 0;
        }
        if ($newdimid) {
            $this->set_mapping($dim, $olddimid, $newdimid);
        } else {
            throw new restore_step_exception('error_qtype_matrix_missing_'.$dim.'_record');
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
        $oldweightid = $data->id;

        if ($this->is_question_created()) {
            $data->colid = $this->get_mappingid('col', $data->colid);
            $data->rowid = $this->get_mappingid('row', $data->rowid);
            $newitemid = $DB->insert_record('qtype_matrix_weights', $data);
        } else {
            $existingcolid = $this->get_mappingid('col', $data->colid);
            $existingrowid = $this->get_mappingid('row', $data->rowid);
            $newquestionid = $this->get_new_parentid('question');
            if ($existingcolid && $existingrowid && $newquestionid) {
                $store = new question_matrix_store();
                $weights = $store->get_matrix_weights_by_question_id($newquestionid);
                foreach ($weights as $weight) {
                    if ($existingcolid == $weight->colid && $existingrowid == $weight->rowid) {
                        $existingweight = $weight;
                        break;
                    }
                }
            }
            $newitemid = $existingweight->id ?? 0;
        }
        if ($newitemid) {
            $this->set_mapping('weight', $oldweightid, $newitemid);
        } else {
            throw new restore_step_exception('error_qtype_matrix_missing_weight_record');
        }
    }

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
        foreach ($response as $responsekey => $responseval) {
            if ($responsekey == '_order') {
                $recodedresponse['_order'] = $this->recode_choice_order($responseval);
            } else if (substr($responsekey, 0, 4) == 'cell') {
                $responsekeynocell = substr($responsekey, 4);
                // Example: cellROWID_COLID for multiple, cellROWID for single
                $responsekeyids = explode('_', $responsekeynocell);
                $newrowid = $this->get_mappingid('row', $responsekeyids[0]);
                // TODO: This seems broken. $responseval is either 'on' or a oldcolid
                // If 'on' This is 0
                $newcolid = $this->get_mappingid('col', $responseval) ?? 0;
                if (count($responsekeyids) == 1) {
                    $recodedresponse['cell' . $newrowid] = $newcolid;
                } else if (count($responsekeyids) == 2) {
                    // TODO: See above, this is the multiple case where $newcolid is 0, so it would be cellOLDROWID_0 = 0
                    $recodedresponse['cell' . $newrowid . '_' . $newcolid] = $newcolid;
                } else {
                    // Fallback, probably never happens
                    $recodedresponse[$responsekey] = $responseval;
                }
            } else {
                $recodedresponse[$responsekey] = $responseval;
            }
        }
        return $recodedresponse;
    }

    /**
     * Recode the choice order as stored in the response.
     *
     * @param string $oldroworder the original order.
     * @return string the recoded order.
     */
    protected function recode_choice_order(string $oldroworder): string {
        $neworder = [];
        foreach (explode(',', $oldroworder) as $oldrowid) {
            $newrowid = $this->get_mappingid('row', $oldrowid);
            if ($newrowid) {
                $neworder[] = $newrowid;
            } else {
                throw new restore_step_exception('error_qtype_matrix_missing_attempt_order_id');
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
                    // FIXME: Why is this maybe necessary?
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
