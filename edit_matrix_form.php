<?php

/**
 * The question type class for the matrix question type.
 *
 */
require_once($CFG->dirroot . '/question/type/edit_question_form.php');

/**
 * matrix editing form definition. For information about the Moodle forms library,
 * which is based on the HTML Quickform PEAR library 
 * 
 * @see http://docs.moodle.org/en/Development:lib/formslib.php 
 */
class qtype_matrix_edit_form extends question_edit_form implements ArrayAccess
{
    //How many elements are added each time somebody click the add row/add column button.
    const DEFAULT_REPEAT_ELEMENTS = 1; 
    //How many rows 
    const DEFAULT_ROWS = 4; 
    //How many cols 
    const DEFAULT_COLS = 2; 

    function qtype()
    {
        return 'matrix';
    }

    function definition_inner($mform)
    {
        $this->question->options = (isset($this->question->options)) ? $this->question->options : (object)array();

        $this->add_multiple();
        $this->add_grading();

        // mod_ND : BEGIN
        if (get_config('qtype_matrix', 'allow_dnd_ui')) {
            $this->add_selectyesno('use_dnd_ui', get_string('use_dnd_ui', 'qtype_matrix'));
        }
        // mod_ND : END

        $mform->addElement('advcheckbox', 'shuffleanswers', get_string('shuffleanswers', 'qtype_matrix'), null, null, [0, 1]);
        $mform->addHelpButton('shuffleanswers', 'shuffleanswers', 'qtype_matrix');
        $mform->setDefault('shuffleanswers', 1);
    }

    /**
     * Override if you need to setup the form depending on current values.
     * This method is called after definition(), data submission and set_data().
     * All form setup that is dependent on form values should go in here.
     */
    function definition_after_data()
    {
        $this->add_matrix();
        $this->add_javascript($this->get_javascript());
    }

    function set_data($question)
    {
        $is_new = empty($question->id) || empty($question->options->rows);

        if (!$is_new) {

            $options = $question->options;

            $question->multiple = $options->multiple ? '1' : '0';
            $question->grademethod = $options->grademethod;
            $question->shuffleanswers = $options->shuffleanswers ? '1' : '0';
            $question->use_dnd_ui = $options->use_dnd_ui ? '1' : '0';
            $question->rowshort = [];
            $question->rowlong = [];
            $question->rowfeedback = [];
            $question->rowid = [];
            foreach ($options->rows as $row) {
                $question->rowshort[] = $row->shorttext;
                $question->rowlong[] = $row->description;
                $question->rowfeedback[] = $row->feedback;
                $question->rowid[] = $row->id;
            }

            $question->colshort = array();
            $question->collong = array();
            $question->colid = array();
            foreach ($options->cols as $col) {
                $question->colshort[] = $col->shorttext;
                $question->collong[] = $col->description;
                $question->colid[] = $col->id;
            }

            $row_index = 0;
            foreach ($options->rows as $row) {
                $col_index = 0;
                foreach ($options->cols as $col) {
                    $cell_name = qtype_matrix_grading::cell_name($row_index, $col_index, $options->multiple);
                    $weight = $options->weights[$row->id][$col->id];

                    if ($options->multiple) {
                        $value = ($weight > 0) ? 'on' : '';
                        $question->{$cell_name} = $value;
                    } else {
                        if ($weight > 0) {
                            $question->{$cell_name} = $col_index;
                            break;
                        }
                    }
                    $col_index++;
                }
                $row_index++;
            }
        }
        /* set data should be called on new questions to set up course id, etc
         * after setting up values for question
         */
        parent::set_data($question);
    }

    function validation($data, $files)
    {
        global $CFG;
        $errors = parent::validation($data, $files);
        if (!property_exists($CFG, 'qtype_matrix_show_non_kprime_gui') || $CFG->qtype_matrix_show_non_kprime_gui !== '0') {
            if ($this->col_count($data) == 0) {
                $errors['colshort[0]'] = qtype_matrix::get_string('mustdefine1by1');
            }

            if ($this->row_count($data) == 0) {
                $errors['rowshort[0]'] = qtype_matrix::get_string('mustdefine1by1');
            }
        } else {
            if ($this->col_count($data) != 2) {
                $errors['colshort[0]'] = qtype_matrix::get_string('mustdefine1by1');
            }

            if ($this->row_count($data) != 4) {
                $errors['rowshort[0]'] = qtype_matrix::get_string('mustdefine1by1');
            }
        }
        $grading = qtype_matrix::grading($data['grademethod']);
        $grading_errors = $grading->validation($data);

        $errors = array_merge($errors, $grading_errors);
        return $errors ? $errors : true;
    }

    protected function col_count($data)
    {
        return count($data['colshort']);
    }

    protected function row_count($data)
    {
        return count($data['rowshort']);
    }

    //elements
    public function add_multiple()
    {
        // multiple allowed
        global $CFG;
        if (!property_exists($CFG, 'qtype_matrix_show_non_kprime_gui') || $CFG->qtype_matrix_show_non_kprime_gui !== '0') {
            $this->add_selectyesno('multiple', qtype_matrix::get_string('multipleallowed'));
            $this->set_default('multiple', false);
        } else {
            $this->_form->addElement('hidden', 'multiple', false);
            $this->_form->setType('multiple', PARAM_RAW);
        }
    }

    public function add_grading()
    {
        // grading method.
        $default_grading = qtype_matrix::defaut_grading();
        $default_grading_name = $default_grading->get_name();
        $gradings = qtype_matrix::gradings();

        $radioarray = array();

        foreach ($gradings as $grading) {
            $radioarray[] =& $this->_form->createElement('radio', 'grademethod', '', $grading->get_title(), $grading->get_name(), '');
        }

        $this->_form->addGroup($radioarray, 'grademethod', qtype_matrix::get_string('grademethod'), array('<br>'), false);
        $this->_form->setDefault('grademethod', $default_grading_name);
        $this->add_help_button('grademethod');
    }

    function add_matrix()
    {
        global $CFG;
        $mform = $this->_form;
        $data = $mform->exportValues();

        if (isset($_POST['colshort'])) {
            $cols_count = count($_POST['colshort']);
        } else if (isset($this->question->options->cols) && count($this->question->options->cols) > 0) {
            $cols_count = count($this->question->options->cols);
        } else {
            $cols_count = self::DEFAULT_COLS;
        }
        $add_cols = optional_param('add_cols', '', PARAM_TEXT);
        if ($add_cols) {
            $cols_count++;
        }

        if (isset($_POST['rowshort'])) {
            $rows_count = count($_POST['rowshort']);
        } else if (isset($this->question->options->rows) && count($this->question->options->rows) > 0) {
            $rows_count = count($this->question->options->rows);
        } else {
            $rows_count = self::DEFAULT_ROWS;
        }
        if ($add_rows = optional_param('add_rows', '', PARAM_TEXT)) {
            $rows_count++;
        }

        $grademethod = isset($data['grademethod']) ? $data['grademethod'] : qtype_matrix::defaut_grading()->get_name();
        $grading = qtype_matrix::grading($grademethod);
        $multiple = isset($data['multiple']) ? $data['multiple'] : true;

        $matrix = array();
        $html = '<table class="quedit matrix"><thead><tr>';
        $html .= '<th></th>';
        $matrix[] = $this->create_static($html);
        for ($col = 0; $col < $cols_count; $col++) {
            $matrix[] = $this->create_static('<th>');
            $matrix[] = $this->create_static('<div class="input-group">');
            $matrix[] = $this->create_text("colshort[$col]", false);

            $popup = $this->create_htmlpopup("collong[$col]", qtype_matrix::get_string('collong'));
            $matrix = array_merge($matrix, $popup);

            $matrix[] = $this->create_hidden("colid[$col]");
            $matrix[] = $this->create_static('</div>');
            $matrix[] = $this->create_static('</th>');
        }

        $matrix[] = $this->create_static('<th>');
        $matrix[] = $this->create_static(qtype_matrix::get_string('rowfeedback'));
        $matrix[] = $this->create_static('</th>');

        $matrix[] = $this->create_static('<th>');
        if (!property_exists($CFG, 'qtype_matrix_show_non_kprime_gui') || $CFG->qtype_matrix_show_non_kprime_gui !== '0') {
            $matrix[] = $this->create_submit('add_cols', '  ', array('class' => 'button add'));
            $this->register_no_submit_button('add_cols');
        }
        $matrix[] = $this->create_static('</th>');

        $matrix[] = $this->create_static('</tr></thead><tbody>');

        for ($row = 0; $row < $rows_count; $row++) {
            $matrix[] = $this->create_static('<tr>');
            $matrix[] = $this->create_static('<td>');

            $matrix[] = $this->create_static('<div class="input-group">');
            $matrix[] = $this->create_text("rowshort[$row]", false);

            $question_popup = $this->create_htmlpopup("rowlong[$row]", qtype_matrix::get_string('rowlong'));
            $matrix = array_merge($matrix, $question_popup);
            $matrix[] = $this->create_hidden("rowid[$row]");

            $matrix[] = $this->create_static('</div>');
            $matrix[] = $this->create_static('</td>');

            for ($col = 0; $col < $cols_count; $col++) {
                $matrix[] = $this->create_static('<td>');
                $cell_content = $grading->create_cell_element($mform, $row, $col, $multiple);
                $cell_content = $cell_content ? $cell_content : $this->create_static('');
                $matrix[] = $cell_content;
                $matrix[] = $this->create_static('</td>');
            }

            $matrix[] = $this->create_static('<td class="feedback">');

            $feedback_popup = $this->create_htmlpopup("rowfeedback[$row]", qtype_matrix::get_string('rowfeedback'));
            $matrix = array_merge($matrix, $feedback_popup);

            $matrix[] = $this->create_static('</td>');

            $matrix[] = $this->create_static('<td></td>');

            $matrix[] = $this->create_static('</tr>');
        }

        $matrix[] = $this->create_static('<tr>');
        $matrix[] = $this->create_static('<td>');
        if (!property_exists($CFG, 'qtype_matrix_show_non_kprime_gui') || $CFG->qtype_matrix_show_non_kprime_gui !== '0') {
            $matrix[] = $this->create_submit('add_rows', '  ', array('class' => 'button add'));
            $this->register_no_submit_button('add_rows');
        }
        $matrix[] = $this->create_static('</td>');
        for ($col = 0; $col < $cols_count; $col++) {
            $matrix[] = $this->create_static('<td>');
            $matrix[] = $this->create_static('</td>');
        }
        $matrix[] = $this->create_static('</tr>');
        $matrix[] = $this->create_static('</tbody></table>');

        $matrixheader = $this->create_header('matrixheader');
        $matrix_group = $this->create_group('matrix', null, $matrix, '', false);

        if (isset($this['tagsheader'])) {
            $this->insert_element_before($matrixheader, 'tagsheader');
            $refresh_button = $this->create_submit('refresh_matrix');
            $this->register_no_submit_button('refresh_matrix');
            $this->disabled_if('refresh_matrix', 'grademethod', 'eq', 'none');
            $this->disabled_if('defaultgrade', 'grademethod', 'eq', 'none');
            $this->insert_element_before($refresh_button, 'tagsheader');
            $this->insert_element_before($matrix_group, 'tagsheader');
        } else {
            $this[] = $matrixheader;
            $refresh_button = $this->create_submit('refresh_matrix');
            $this->register_no_submit_button('refresh_matrix');
            $this->disabled_if('refresh_matrix', 'grademethod', 'eq', 'none');
            $this->disabled_if('defaultgrade', 'grademethod', 'eq', 'none');
            $this[] = $refresh_button;
            $this[] = $matrix_group;
        }

        if ($cols_count > 1 && (empty($this->question->id) || empty($this->question->options->rows))) {
            $this->set_default('colshort[0]', qtype_matrix::get_string('true'));
            $this->set_default('colshort[1]', qtype_matrix::get_string('false'));

        }
        $this->_form->setExpanded('matrixheader');
    }

    public function get_javascript()
    {
        return <<<EOT
        
        var YY = null;               
        
        window.mtrx_current = false;
        function mtrx_popup(id)
        {        
            var current_id = window.mtrx_current;
            var new_id = '#' + id;
            if(current_id == false)
            {
                node = YY.one(new_id);
                node.setStyle('display', 'block');
                window.mtrx_current = new_id;
            }
            else if(current_id == new_id)
            {
                node = YY.one(window.mtrx_current);
                node.hide();
                window.mtrx_current = false;
            }
            else
            {
                node = YY.one(current_id);
                node.hide();
                
                node = YY.one(new_id)
                node.setStyle('display', 'block');
                window.mtrx_current = new_id;
            }
        }        
        
        YUI(M.yui.loader).use('node', function(Y) {
            YY = Y;
            }); 
        
        
EOT;
    }

    //utility functions

    protected function create_name()
    {
        static $count = 0;
        return '__j' . $count++;
    }

    protected function create_javascript($js)
    {
        $html = '<script type="text/javascript">';
        $html .= $js;
        $html .= '</script>';
        $name = $this->create_name();
        return $this->_form->createElement('static', $name, null, $html);
    }

    protected function create_static($html)
    {
        $name = $this->create_name();
        return $this->_form->createElement('static', $name, null, $html);
    }

    protected function create_text($name, $label = '')
    {
        if ($label === '') {
            $short_name = explode('[', $name);
            $short_name = reset($short_name);
            $label = qtype_matrix::get_string($short_name);
        }
        return $this->_form->createElement('text', $name, $label);
    }

    protected function create_htmleditor($name, $label = '')
    {
        if ($label === '') {
            $short_name = explode('[', $name);
            $short_name = reset($short_name);
            $label = qtype_matrix::get_string($short_name);
        }
        return $this->_form->createElement('htmleditor', $name, $label);
    }

    protected function create_htmlpopup($name, $label = '')
    {
        static $pop_count = 0;
        $pop_count++;
        $id = "htmlpopup$pop_count";

        $result = array();
        $result[] = $this->create_static('<a class="pbutton input-group-addon" href="#" onclick="mtrx_popup(\'' . $id . '\');return false;" >...</a>');
        $result[] = $this->create_static('<div id="' . $id . '" class="popup">');
        $result[] = $this->create_static('<div>');
        $result[] = $this->create_static('<a class="pbutton close" href="#" onclick="mtrx_popup(\'' . $id . '\');return false;" >&nbsp;&nbsp;&nbsp;</a>');
        $result[] = $this->create_static('<span class="title">');
        $result[] = $this->create_static($label);
        $result[] = $this->create_static('</span>');
        $result[] = $this->create_htmleditor($name);
        $result[] = $this->create_static('</div>');
        $result[] = $this->create_static('</div>');
        return $result;
    }

    protected function create_hidden($name, $value = null)
    {
        return $this->_form->createElement('hidden', $name, $value);
    }

    protected function create_group($name = null, $label = null, $elements = null, $separator = '', $appendName = true)
    {
        if ($label === '') {
            $short_name = explode('[', $name);
            $short_name = reset($short_name);
            $label = qtype_matrix::get_string($short_name);
        }
        return $this->_form->createElement('group', $name, $label, $elements, $separator, $appendName);
    }

    protected function create_header($name, $label = '')
    {
        if ($label === '') {
            $short_name = explode('[', $name);
            $short_name = reset($short_name);
            $label = qtype_matrix::get_string($short_name);
        }
        return $this->_form->createElement('header', $name, $label);
    }

    protected function create_submit($name, $label = '', $attributes = null)
    {
        if ($label === '') {
            $short_name = explode('[', $name);
            $short_name = reset($short_name);
            $label = qtype_matrix::get_string($short_name);
        }
        return $this->_form->createElement('submit', $name, $label, $attributes);
    }

    protected function add_javascript($js)
    {
        $this[] = $element = $this->create_javascript($js);
        return $element;
    }

    protected function add_static($html)
    {
        return $this->_form->addElement('static', null, null, $html);
    }

    protected function add_text($name, $label = '')
    {
        if ($label === '') {
            $short_name = explode('[', $name);
            $short_name = reset($short_name);
            $label = qtype_matrix::get_string($short_name);
        }
        return $this->_form->addElement('text', $name, $label);
    }

    protected function add_htmleditor($name, $label = '')
    {
        if ($label === '') {
            $short_name = explode('[', $name);
            $short_name = reset($short_name);
            $label = qtype_matrix::get_string($short_name);
        }
        return $this->_form->addElement('htmleditor', $name, $label);
    }

    protected function add_hidden($name, $value = null)
    {
        return $this->_form->addElement('hidden', $name, $value);
    }

    protected function add_group($name = null, $label = null, $elements = null, $separator = '', $appendName = true)
    {
        if ($label === '') {
            $short_name = explode('[', $name);
            $short_name = reset($short_name);
            $label = qtype_matrix::get_string($short_name);
        }
        return $this->_form->addElement('group', $name, $label, $elements, $separator, $appendName);
    }

    protected function add_header($name, $label = '')
    {
        if ($label === '') {
            $short_name = explode('[', $name);
            $short_name = reset($short_name);
            $label = qtype_matrix::get_string($short_name);
        }
        return $this->_form->addElement('header', $name, $label);
    }

    protected function add_selectyesno($name, $label = '')
    {
        if ($label === '') {
            $short_name = explode('[', $name);
            $short_name = reset($short_name);
            $label = qtype_matrix::get_string($short_name);
        }
        $result = $this->_form->addElement('advcheckbox', $name, $label);
        return $result;
    }

    protected function add_select($name, $label = '', $options = null)
    {
        if ($label === '') {
            $short_name = explode('[', $name);
            $short_name = reset($short_name);
            $label = qtype_matrix::get_string($short_name);
        }
        return $this->_form->addElement('select', $name, $label, $options);
    }

    protected function add_submit($name, $label = '')
    {
        if ($label === '') {
            $short_name = explode('[', $name);
            $short_name = reset($short_name);
            $label = qtype_matrix::get_string($short_name);
        }
        return $this->_form->addElement('submit', $name, $label);
    }

    protected function add_help_button($elementname, $identifier = null, $component = 'qtype_matrix', $linktext = '', $suppresscheck = false)
    {
        if (is_null($identifier)) {
            $identifier = $elementname;
        }
        $this->_form->addHelpButton($elementname, $identifier, $component, $linktext, $suppresscheck);
    }

    protected function add_element($element)
    {
        return $this->_form->addElement($element);
    }

    protected function set_default($name, $value)
    {
        $this->_form->setDefault($name, $value);
    }

    protected function element_exists($name)
    {
        return $this->_form->elementExists($name);
    }

    protected function insert_element_before($element, $before_name)
    {
        return $this->_form->insertElementBefore($element, $before_name);
    }

    protected function disabled_if($elementName, $dependentOn, $condition = 'notchecked', $value = '1')
    {
        $this->_form->disabledIf($elementName, $dependentOn, $condition, $value);
    }

    protected function register_no_submit_button($name)
    {
        $this->_form->registerNoSubmitButton($name);
    }

    public function offsetExists($offset)
    {
        return $this->_form->elementExists($offset);
    }

    public function offsetGet($offset)
    {
        return $this->_form->getElement($offset);
    }

    public function offsetSet($offset, $value)
    {
        $this->_form->addElement($value);
    }

    public function offsetUnset($offset)
    {
        $this->_form->removeElement($offset);
    }

}
