<?php

/**
 * The question type class for the matrix question type.
 *
 */
defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/questionlib.php');
require_once (dirname(__FILE__)) . '/qtype_matrix_grading.class.php';

// renderer for the whole question - needs a matching class
// see matrix_qtype::matrix_renderer_options
//define('QTYPE_MATRIX_RENDERER_MATRIX', 'matrix');

/**
 * The matrix question class
 *
 * Pretty simple concept - a matrix with a number of different grading methods and options.
 */
class qtype_matrix extends question_type
{

    public static function get_string($identifier, $component = 'qtype_matrix', $a = null)
    {
        return get_string($identifier, $component, $a);
    }

    public static function gradings()
    {
        return qtype_matrix_grading::gradings();
    }

    public static function grading($type)
    {
        return qtype_matrix_grading::create($type);
    }

    public static function defaut_grading()
    {
        return qtype_matrix_grading::default_grading();
    }

    function name()
    {
        return 'matrix';
    }

    /**
     * Deletes question from the question-type specific tables
     *
     * @param integer $questionid The question being deleted
     * @param integer $contextid The context id
     * @return boolean to indicate success of failure.
     */
    function delete_question_options($questionid, $contextid = null)
    {
        if (empty($questionid)) {
            return false;
        }

        global $DB;
        global $CFG;

        $prefix = $CFG->prefix;

        //wheights
        $sql = "DELETE FROM {$prefix}question_matrix_weights
                WHERE {$prefix}question_matrix_weights.rowid IN 
                      (
                      SELECT rows.id FROM {$prefix}question_matrix_rows  AS rows
                      INNER JOIN {$prefix}question_matrix      AS matrix ON rows.matrixid = matrix.id
                      WHERE matrix.questionid = $questionid
                      )";
        $DB->execute($sql);

        //rows
        $sql = "DELETE FROM {$prefix}question_matrix_rows
                WHERE {$prefix}question_matrix_rows.matrixid IN 
                      (
                      SELECT matrix.id FROM {$prefix}question_matrix AS matrix
                      WHERE matrix.questionid = $questionid
                      )";
        $DB->execute($sql);

        //cols
        $sql = "DELETE FROM {$prefix}question_matrix_cols
                WHERE {$prefix}question_matrix_cols.matrixid IN 
                      (
                      SELECT matrix.id FROM {$prefix}question_matrix AS matrix
                      WHERE matrix.questionid = $questionid
                      )";
        $DB->execute($sql);

        //matrix
        $sql = "DELETE FROM {$prefix}question_matrix WHERE questionid = $questionid";
        $DB->execute($sql);
        // attempts   
        $sql = "DELETE FROM {$prefix}question_attempt_step_data USING {$prefix}question_attempt_steps, {$prefix}question_attempts WHERE {$prefix}question_attempt_steps.id = {$prefix}question_attempt_step_data.attemptstepid AND {$prefix}question_attempts.id = {$prefix}question_attempt_steps.questionattemptid AND {$prefix}question_attempts.questionid=$questionid";
        $DB->execute($sql);


        return true;
    }

    /**
     * Deletes question from the question-type specific tables
     *
     * @param integer $questionid The question being deleted
     * @param integer $contextid
     * @return boolean to indicate success of failure.
     */
    function delete_question($questionid, $contextid = null)
    {
        if (empty($questionid)) {
            return false;
        }

        global $DB;

        $transaction = $DB->start_delegated_transaction();
        $this->delete_question_options($questionid);
        parent::delete_question($questionid, $contextid);

        $transaction->allow_commit();

        return true;
    }

    /**
     * @return boolean true if this question type sometimes requires manual grading.
     */
    function is_manual_graded()
    {
        return true;
    }

    /**
     * 
     * @param object $question
     * @return boolean
     */
    function get_question_options($question)
    {
        parent::get_question_options($question);
        $matrix = self::retrieve_matrix($question->id);
        if ($matrix) {
            $question->options->rows = $matrix->rows;
            $question->options->cols = $matrix->cols;
            $question->options->weights = $matrix->weights;
            $question->options->grademethod = $matrix->grademethod;
            $question->options->shuffleanswers = isset($matrix->shuffleanswers) ? $matrix->shuffleanswers : true; // allow for old versions which don't have this field
            $question->options->use_dnd_ui = $matrix->use_dnd_ui;
            $question->options->multiple = $matrix->multiple;
            $question->options->renderer = $matrix->renderer;
        } else {
            $question->options->rows = array();
            $question->options->cols = array();
            $question->options->weights = array(array());
            $question->options->grademethod = self::defaut_grading()->get_name();
            $question->options->shuffleanswers = true;
            $question->options->use_dnd_ui = false;
            $question->options->multiple = true;
        }
        return true;
    }

    static function retrieve_matrix($question_id)
    {
        if (empty($question_id)) {
            return null;
        }

        static $results = array();
        if (isset($results[$question_id])) {
            return $results[$question_id];
        }

        global $DB;
        $matrix = $DB->get_record('question_matrix', array('questionid' => $question_id));

        if (empty($matrix)) {
            return false;
        } else {
            $matrix->multiple = (bool) $matrix->multiple;
        }
        $matrix->rows = $DB->get_records('question_matrix_rows', array('matrixid' => $matrix->id), 'id ASC');
        $matrix->rows = $matrix->rows ? $matrix->rows : array();

        $matrix->cols = $DB->get_records('question_matrix_cols', array('matrixid' => $matrix->id), 'id ASC');
        $matrix->cols = $matrix->cols ? $matrix->cols : array();

        global $CFG;
        $prefix = $CFG->prefix;
        $sql = "SELECT weights.* 
                FROM {$prefix}question_matrix_weights AS weights
                WHERE 
                    rowid IN (SELECT rows.id FROM {$prefix}question_matrix_rows     AS rows 
                              INNER JOIN {$prefix}question_matrix                   AS matrix ON rows.matrixid = matrix.id
                              WHERE matrix.questionid = $question_id)
                    OR
                              
                    colid IN (SELECT cols.id FROM {$prefix}question_matrix_cols     AS cols
                              INNER JOIN {$prefix}question_matrix                   AS matrix ON cols.matrixid = matrix.id
                              WHERE matrix.questionid = $question_id)
               ";
        $matrix->rawweights = $DB->get_records_sql($sql);

        $matrix->weights = array();

        foreach ($matrix->rows as $r) {
            $matrix->fullmatrix[$r->id] = array();
            foreach ($matrix->cols as $c) {
                $matrix->weights[$r->id][$c->id] = 0;
            }
        }
        foreach ($matrix->rawweights as $w) {
            $matrix->weights[$w->rowid][$w->colid] = (float) $w->weight;
        }
        $results[$question_id] = $matrix;
        return $matrix;
    }

    /**
     * Initialise the common question_definition fields.
     *
     * @param question_definition $question the question_definition we are creating.
     * @param object $questiondata the question data loaded from the database.
     */
    protected function initialise_question_instance(question_definition $question, $questiondata)
    {
        parent::initialise_question_instance($question, $questiondata);
        $question->rows = $questiondata->options->rows;
        $question->cols = $questiondata->options->cols;
        $question->weights = $questiondata->options->weights;
        $question->grademethod = $questiondata->options->grademethod;
        $question->shuffleanswers = $questiondata->options->shuffleanswers;
        $question->multiple = $questiondata->options->multiple;
    }

    /**
     * Saves question-type specific options.
     * This is called by {@link save_question()} to save the question-type specific data.
     *
     * @param object $question This holds the information from the editing form, it is not a standard question object.
     * @return object $result->error or $result->noticeyesno or $result->notice
     */
    function save_question_options($question)
    {
        global $DB, $CFG;
        $prefix = $CFG->prefix;
        //parent::save_question_options($question);

        $question_id = isset($question->id) ? $question->id : false;

        $transaction = $DB->start_delegated_transaction();

        $matrix = $DB->get_record('question_matrix', array('questionid' => $question_id));

        if (empty($matrix)) {
            $matrix = (object) array(
                    'questionid' => $question->id,
                    'multiple' => $question->multiple,
                    'grademethod' => $question->grademethod,
                    'use_dnd_ui' => $question->use_dnd_ui,
                    'shuffleanswers' => $question->shuffleanswers,
                    'renderer' => 'matrix'
            );
            $matrix_id = $DB->insert_record('question_matrix', $matrix);
        } else {
            $matrix->questionid = $question->id;
            $matrix->multiple = $question->multiple;
            $matrix->grademethod = $question->grademethod;
            $matrix->shuffleanswers = $question->shuffleanswers;
            $matrix->use_dnd_ui = $question->use_dnd_ui;
            $matrix->renderer = 'matrix';
            $DB->update_record('question_matrix', $matrix);
            $matrix_id = $matrix->id;
        }


        // rows
        $rowids = array(); //mapping for indexes to db ids.
        foreach ($question->rowshort as $i => $short) {
            if ($question->rowid[$i] == '' || (property_exists($question, 'makecopy') && $question->makecopy == '1')) {
                // either the row comes without a pre-existing ID (so it's a newly created question) or the row HAS an ID, but we want to duplicate (so we should also create a new row)
                if (empty($short)) {
                    break;
                }
                $row = (object) array(
                        'matrixid' => $matrix_id,
                        'shorttext' => $question->rowshort[$i],
                        'description' => $question->rowlong[$i],
                        'feedback' => $question->rowfeedback[$i]
                );
                $newid = $DB->insert_record('question_matrix_rows', $row);
                $rowids[] = $newid;
            } else {
                // TODO: Add a possibility to delete if (empty($short)) 
                $row = (object) array(
                        'id' => $question->rowid[$i],
                        'matrixid' => $matrix_id,
                        'shorttext' => $question->rowshort[$i],
                        'description' => $question->rowlong[$i],
                        'feedback' => $question->rowfeedback[$i]
                );
                $DB->update_record('question_matrix_rows', $row);
                $rowids[] = $question->rowid[$i];
            }
        }

        // cols
        $colids = array();
        foreach ($question->colshort as $i => $short) {
            if ($question->colid[$i] == '' || (property_exists($question, 'makecopy') && $question->makecopy == '1')) {
                // same spiel as with the rows.
                if (empty($short)) {
                    break;
                }
                $col = (object) array(
                        'matrixid' => $matrix_id,
                        'shorttext' => $question->colshort[$i],
                        'description' => $question->collong[$i]
                );

                $newid = $DB->insert_record('question_matrix_cols', $col);
                $colids[] = $newid;
            } else {
                // TODO: Add a possibility to delete if (empty($short)) {
                $col = (object) array(
                        'id' => $question->colid[$i],
                        'matrixid' => $matrix_id,
                        'shorttext' => $question->colshort[$i],
                        'description' => $question->collong[$i]
                );
                $DB->update_record('question_matrix_cols', $col);
                $colids[] = $question->colid[$i];
            }
        }

        /**
         * Wheights
         * 
         * First we delete all weights. (There is no danger of deleting the original weights when making a copy, because we are anyway deleting only weights associated with our newly created question ID).
         * Then we recreate them. (Because updating is too much of a pain)
         * 
         */
        $sql = "DELETE FROM {$prefix}question_matrix_weights
                WHERE {$prefix}question_matrix_weights.rowid IN
                (
                 SELECT rows.id FROM {$prefix}question_matrix_rows  AS rows
                 INNER JOIN {$prefix}question_matrix AS matrix ON rows.matrixid = matrix.id
                 WHERE matrix.questionid = $question_id
                )";
        $DB->execute($sql);


        $weights = array();

        /**
         * When we switch from multiple answers to single answers (or the other
         * way around) we loose answers. 
         * 
         * To avoid loosing information when we switch, we test if the weight matrix is empty. 
         * If the weight matrix is empty we try to read from the other 
         * representation directly from POST data.
         * 
         * We read from the POST because post data are not read into the question
         * object because there is no corresponding field.
         * 
         * This is bit hacky but it is safe. The to_weight_matrix returns only 
         * 0 or 1.
         */
        if ($question->multiple) {
            $weights = $this->to_weigth_matrix($question, true);
            if ($this->is_matrix_empty($weights)) {
                $weights = $this->to_weigth_matrix($_POST, false);
            }
        } else {
            $weights = $this->to_weigth_matrix($question, false);
            if ($this->is_matrix_empty($weights)) {
                $weights = $this->to_weigth_matrix($_POST, true);
            }
        }

        foreach ($rowids as $row_index => $row_id) {
            foreach ($colids as $col_index => $col_id) {
                $value = $weights[$row_index][$col_index];
                if ($value) {
                    $weight = (object) array(
                            'rowid' => $row_id,
                            'colid' => $col_id,
                            'weight' => 1
                    );
                    $DB->insert_record('question_matrix_weights', $weight);
                }
            }
        }

        $transaction->allow_commit();
    }

    /**
     * Transform the weight from the edit-form's representation to a standard matrix 
     * representation
     * 
     * Input data is either
     * 
     *      $question->{cell0_1] = 1
     * 
     * or
     * 
     *      $question->{cell0] = 3
     * 
     * Output
     * 
     *      [ 1 0 1 0 ]
     *      [ 0 0 0 1 ]
     *      { 1 1 1 0 ]
     *      [ 0 1 0 1 ]
     * 
     * 
     * @param object $data              Question's data, either from the question object or from the post
     * @param boolean $from_multiple    Whether we extract from multiple representation or not
     * @result array                    The weights
     */
    public function to_weigth_matrix($data, $from_multiple)
    {
        $data = (object) $data;
        $result = array();
        $row_count = 20;
        $col_count = 20;

        //init
        for ($row = 0; $row < $row_count; $row++) {
            for ($col = 0; $col < $col_count; $col++) {
                $result[$row][$col] = 0;
            }
        }

        if ($from_multiple) {
            for ($row = 0; $row < $row_count; $row++) {
                for ($col = 0; $col < $col_count; $col++) {
                    $key = qtype_matrix_grading::cell_name($row, $col, $from_multiple);
                    $value = isset($data->{$key}) ? $data->{$key} : 0;
                    $result[$row][$col] = $value ? 1 : 0;
                }
            }
        } else {
            for ($row = 0; $row < $row_count; $row++) {
                $key = qtype_matrix_grading::cell_name($row, 0, $from_multiple);
                if (isset($data->{$key})) {
                    $col = $data->{$key};
                    $result[$row][$col] = 1;
                }
            }
        }
        return $result;
    }

    /**
     * True if the matrix is empty (contains only zeroes). False otherwise.
     * 
     * @param array $matrix Array of arrays
     * @return boolean True if the matrix contains only zeros. False otherwise
     */
    public function is_matrix_empty($matrix)
    {
        foreach ($matrix as $row) {
            foreach ($row as $value) {
                if ($value && $value > 0) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * This method should be overriden if you want to include a special heading or some other
     * html on a question editing page besides the question editing form.
     *
     * @param question_edit_form $mform a child of question_edit_form
     * @param object $question
     * @param string $wizardnow is '' for first page.
     */
    public function display_question_editing_page($mform, $question, $wizardnow)
    {
        global $OUTPUT;
        $heading = $this->get_heading(empty($question->id));

        if (get_string_manager()->string_exists('pluginname_help', $this->plugin_name())) {
            echo $OUTPUT->heading_with_help($heading, 'pluginname', $this->plugin_name());
        } else {
            echo $OUTPUT->heading_with_help($heading, $this->name(), $this->plugin_name());
        }
        $mform->display();
    }

    // mod_ND : BEGIN
    public function extra_question_fields()
    {
        return array('question_matrix', 'use_dnd_ui');
    }

    // mod_ND : END
}
