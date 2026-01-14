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

namespace qtype_matrix\output;

defined('MOODLE_INTERNAL') || die();

use qtype_matrix\local\setting;
use qtype_matrix_test_helper;
use advanced_testcase;
use question_display_options;
use testable_question_attempt;
use question_attempt_step;

global $CFG;
require_once $CFG->dirroot . '/question/type/matrix/tests/helper.php';

/**
 * Unit tests for the matrix question definition class.
 */
class formulation_and_controls_test extends advanced_testcase {

    /**
     * This is more like a test whether this function will function at all for now.
     * @dataProvider export_for_template_data_provider
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public function test_export_for_template(
        bool $allowautopass,
        bool $questionhasautopass
    ): void {
        global $PAGE;
        $this->resetAfterTest();
        set_config(setting::SETTING_ALLOW_AUTOPASS, $allowautopass, 'qtype_matrix');
        // Prepare the question.
        $question = qtype_matrix_test_helper::make_question('default');
        // Shuffling only makes it harder to test.
        $question->shuffleanswers = false;
        if (!$questionhasautopass) {
            foreach ($question->rows as $row) {
                $row->autopass = false;
            }
        }
        // Simulate the start of an attempt.
        $qa = new testable_question_attempt($question, 0);
        $stepwithresponse = new question_attempt_step();
        $qa->add_step($stepwithresponse);
        $question->start_attempt($stepwithresponse, 1);

        $options = new question_display_options();
        $options->feedback = question_display_options::VISIBLE;
        $options->numpartscorrect = question_display_options::VISIBLE;
        $options->generalfeedback = question_display_options::VISIBLE;
        $options->rightanswer = question_display_options::VISIBLE;
        $options->manualcomment = question_display_options::VISIBLE;
        $options->history = question_display_options::VISIBLE;
        // At the start we don't display any correctness feedback.
        $options->correctness = question_display_options::HIDDEN;

        $displayquestion = new formulation_and_controls($qa, $options);
        $renderer = $PAGE->get_renderer('qtype_matrix');
        $context = $displayquestion->export_for_template($renderer);
        $this->assertNotEquals([], $context);
        $this->assertEquals($question->usedndui, $context['usedndui']);
        $this->assertEquals($questionhasautopass, $context['hasautopassrows']);
        foreach ($context['rows'] as $rowcontext) {
            $this->assertFalse(isset($rowcontext['feedback']));
        }
        // At the start of the attempt no answer feedback should be shown regardless of autopassing.
        $this->ensure_autopass_rowcssclasses(false, $context['rows'][0]);
        $this->ensure_autopass_rowcssclasses(false, $context['rows'][1]);
        $this->ensure_autopass_rowcssclasses(false, $context['rows'][2]);
        $this->ensure_autopass_rowcssclasses(false, $context['rows'][3], true);
        $this->assertEquals(false, $context['rows'][0]['autopassrow']);
        $this->assertEquals(false, $context['rows'][1]['autopassrow']);
        $this->assertEquals(false, $context['rows'][2]['autopassrow']);
        $this->assertEquals(false, $context['rows'][3]['autopassrow']);
        // No response means only show the icons for correct cells.
        $this->assertFalse(isset($context['rows'][0]['cells'][0]['feedback']));
        $this->assertFalse(isset($context['rows'][0]['cells'][1]['feedback']));

        // Now simulate an attempt with a step with a partial answer.
        $qa = new testable_question_attempt($question, 0);
        $stepwithresponse = new question_attempt_step([
            'row0col1' => true,
            'row1col0' => true,
            'row2col2' => true
        ]);
        $qa->add_step($stepwithresponse);
        $question->start_attempt($stepwithresponse, 1);
        // Turn on feedback for the partial answer.
        $options->correctness = question_display_options::VISIBLE;

        $displayquestion = new formulation_and_controls($qa, $options);
        $renderer = $PAGE->get_renderer('qtype_matrix');
        $context = $displayquestion->export_for_template($renderer);

        foreach ($context['rows'] as $rowcontext) {
            $this->assertNotEmpty($rowcontext['feedback']);
        }

        $this->ensure_autopass_rowcssclasses($allowautopass && $questionhasautopass, $context['rows'][0]);
        $this->ensure_autopass_rowcssclasses(false, $context['rows'][1]);
        $this->ensure_autopass_rowcssclasses($allowautopass && $questionhasautopass, $context['rows'][2]);
        $this->ensure_autopass_rowcssclasses(false, $context['rows'][3], true);
        $this->assertEquals($allowautopass && $questionhasautopass, $context['rows'][0]['autopassrow']);
        $this->assertEquals(false, $context['rows'][1]['autopassrow']);
        $this->assertEquals($allowautopass && $questionhasautopass, $context['rows'][2]['autopassrow']);
        $this->assertEquals(false, $context['rows'][3]['autopassrow']);

        // Not checked and not correct, so no feedback.
        $this->assertFalse($context['rows'][0]['cells'][0]['ischecked']);
        $this->assertFalse(isset($context['rows'][0]['cells'][0]['feedbackimage']));

        // Checked (and correct), so feedback.
        $this->assertTrue($context['rows'][0]['cells'][1]['ischecked']);
        $this->assertTrue(isset($context['rows'][0]['cells'][1]['feedbackimage']));

        // Checked (and incorrect), so feedback.
        $this->assertTrue($context['rows'][1]['cells'][0]['ischecked']);
        $this->assertTrue(isset($context['rows'][1]['cells'][0]['feedbackimage']));

        // Not checked but correct, so feedback.
        $this->assertFalse($context['rows'][1]['cells'][1]['ischecked']);
        $this->assertTrue(isset($context['rows'][1]['cells'][1]['feedbackimage']));

        // Neither checked nor correct, so no feedback.
        $this->assertFalse($context['rows'][2]['cells'][0]['ischecked']);
        $this->assertFalse(isset($context['rows'][2]['cells'][0]['feedbackimage']));

        // Not checked but correct, so feedback.
        $this->assertFalse($context['rows'][2]['cells'][1]['ischecked']);
        $this->assertTrue(isset($context['rows'][2]['cells'][1]['feedbackimage']));

        // Checked but incorrect, so feedback.
        $this->assertTrue($context['rows'][2]['cells'][2]['ischecked']);
        $this->assertTrue(isset($context['rows'][2]['cells'][2]['feedbackimage']));
    }

    private function ensure_autopass_rowcssclasses(bool $should, array $rowcontext, bool $othercssclasses = false) {
        if ($should) {
            $this->assertStringContainsString('autopassrow', $rowcontext['rowcssclasses']);
        } else {
            if ($othercssclasses) {
                $this->assertStringNotContainsString('autopassrow', $rowcontext['rowcssclasses']);
            } else {
                $this->assertArrayNotHasKey('rowcssclasses', $rowcontext);
            }
        }
    }

    public function export_for_template_data_provider() {
        return [
            'No autopass, question without autopass' => [false, false],
            'No autopass, question with autopass' => [false, true],
            'Autopass on, question without autopass' => [true, false],
            'Autopass on, question with autopass' => [true, true],
        ];
    }

}

