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

namespace local\grading;

defined('MOODLE_INTERNAL') || die();

use advanced_testcase;
use qtype_matrix\local\grading\kany;
use qtype_matrix\local\setting;
use qtype_matrix_test_helper;
use question_attempt_step;
use testable_question_attempt;

global $CFG;
require_once $CFG->dirroot . '/question/type/matrix/tests/helper.php';

/**
 * Unit tests for the all grading class.
 */
class kany_test extends advanced_testcase {

    /**
     * @dataProvider grade_question_data_provider
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public function test_grade_question(
        string $questiontype,
        bool   $autopass,
        float  $correctgrade,
        float  $incorrectgrade,
        float  $onerowwronggrade,
        float  $completevariationsgrade,
        float  $incompletepartiallycorrectgrade,
        float  $incompleteincorrectgrade,
        float  $kanygrade,
        float  $autopassgrade
    ):void {
        $this->resetAfterTest();
        set_config(setting::SETTING_ALLOW_AUTOPASS, $autopass, 'qtype_matrix');
        $question = qtype_matrix_test_helper::make_question($questiontype);
        $question->shuffleanswers = false;
        $qa = new testable_question_attempt($question, 0);
        $step = new question_attempt_step();
        $qa->add_step($step);
        $question->start_attempt($step, 1);

        $kany = new kany();

        $response = qtype_matrix_test_helper::make_correct_answer($question);
        $this->assertEquals($correctgrade, $kany->grade_question($question, [4,5,6,7], $response));

        $response = qtype_matrix_test_helper::make_incorrect_answer($question);
        $this->assertEquals($incorrectgrade, $kany->grade_question($question, [4,5,6,7], $response));

        $response = qtype_matrix_test_helper::make_first_row_wrong_answer($question);
        $this->assertEquals($onerowwronggrade, $kany->grade_question($question, [4,5,6,7], $response));

        $response = qtype_matrix_test_helper::make_complete_with_variations_answer($question);
        $this->assertEquals($completevariationsgrade, $kany->grade_question($question, [4,5,6,7], $response));

        $response = qtype_matrix_test_helper::make_incomplete_partially_correct_answer($question);
        $this->assertEquals($incompletepartiallycorrectgrade, $kany->grade_question($question, [4,5,6,7], $response));

        $response = qtype_matrix_test_helper::make_incomplete_wrong_answer($question);
        $this->assertEquals($incompleteincorrectgrade, $kany->grade_question($question, [4,5,6,7], $response));

        $response = qtype_matrix_test_helper::make_kany_answer($question);
        $this->assertEquals($kanygrade, $kany->grade_question($question, [4,5,6,7], $response));

        $response = qtype_matrix_test_helper::make_autopass_only_correct_answer($question);
        $this->assertEquals($autopassgrade, $kany->grade_question($question, [4,5,6,7], $response));
    }

    public function grade_question_data_provider():array {
        // correct, incorrect, one row wrong,
        // complete with variations, incomplete partially correct, incomplete wrong,
        // kany specific answer
        return [
            'No autopass, default question' => [
                'default',
                false,
                1, // all rows r
                0, // all rows w
                0.5, // first row w
                0, // d+n: 1+2 c, 3+4 w --- m: 1 cc, 2 cw, 3 cx, 4 xw
                0, // d+n: 1+2 c, 3+4 x --- m: 1 c, 2 cw, 3 x, 4 x
                0, // 1+2 w, 3+4 x
                0, // d: cwwx --- n: 1c 2wc 3cw 4w --- m: 1c 2cw 3 cw 4 xc
                0 // 1+3 w 2+4 c
            ],
            'Autopass on, default question' => ['default', true, 1, 0, 1, 0.5, 0.5, 0, 0, 1],
            'No autopass, nondefault question' => ['nondefault', false, 1, 0, 0.5, 0, 0, 0, 0, 0],
            'Autopass on, nondefault question' => ['nondefault', true, 1, 0, 1, 0.5, 0.5, 0, 0, 1],
            'No autopass, multipletwocorrect question' => ['multipletwocorrect', false, 1, 0, 0.5, 0, 0, 0, 0, 0],
            'Autopass on, multipletwocorrect question' => ['multipletwocorrect', true, 1, 0, 1, 0, 0, 0, 0.5, 1],
        ];
    }

    /**
     * @dataProvider grade_row_data_provider
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public function test_grade_row(
        string $questiontype,
        bool $autopass,
        array $allrowgrades
    ):void {
        $this->resetAfterTest();
        set_config(setting::SETTING_ALLOW_AUTOPASS, $autopass, 'qtype_matrix');
        $question = qtype_matrix_test_helper::make_question($questiontype);
        $question->shuffleanswers = false;
        $qa = new \testable_question_attempt($question, 0);
        $step = new question_attempt_step();
        $qa->add_step($step);
        $question->start_attempt($step, 1);

        $kany = new kany();

        $order = [4,5,6,7];
        foreach ($order as $rowindex => $rowid) {
            $rowgrades = $allrowgrades[$rowindex];
            $correctrowgrade = $rowgrades[0];
            $incorrectrowgrade = $rowgrades[1];
            $onerowwrongrowgrade = $rowgrades[2];
            $completerowvariationsgrade = $rowgrades[3];
            $incompletepartiallyrowcorrectgrade = $rowgrades[4];
            $incompleteincorrectrowgrade = $rowgrades[5];
            $kanygrade = $rowgrades[6];
            $autopassgrade = $rowgrades[7];

            $wrongmessage = 'Wrong for rowindex '.$rowindex;
            $response = qtype_matrix_test_helper::make_correct_answer($question);
            $this->assertEquals($correctrowgrade, $kany->grade_row($question, $rowindex, $response), $wrongmessage);

            $response = qtype_matrix_test_helper::make_incorrect_answer($question);
            $this->assertEquals($incorrectrowgrade, $kany->grade_row($question, $rowindex, $response), $wrongmessage);

            $response = qtype_matrix_test_helper::make_first_row_wrong_answer($question);
            $this->assertEquals($onerowwrongrowgrade, $kany->grade_row($question, $rowindex, $response), $wrongmessage);

            $response = qtype_matrix_test_helper::make_complete_with_variations_answer($question);
            $this->assertEquals($completerowvariationsgrade, $kany->grade_row($question, $rowindex, $response), $wrongmessage);

            $response = qtype_matrix_test_helper::make_incomplete_partially_correct_answer($question);
            $this->assertEquals($incompletepartiallyrowcorrectgrade, $kany->grade_row($question, $rowindex, $response), $wrongmessage);

            $response = qtype_matrix_test_helper::make_incomplete_wrong_answer($question);
            $this->assertEquals($incompleteincorrectrowgrade, $kany->grade_row($question, $rowindex, $response), $wrongmessage);

            $response = qtype_matrix_test_helper::make_kany_answer($question);
            $this->assertEquals($kanygrade, $kany->grade_row($question, $rowindex, $response), $wrongmessage);

            $response = qtype_matrix_test_helper::make_autopass_only_correct_answer($question);
            $this->assertEquals($autopassgrade, $kany->grade_row($question, $rowindex, $response), $wrongmessage);
        }
    }

    public function grade_row_data_provider() {
        // correct, incorrect, one row wrong
        // complete with row variations, incomplete partially correct, incomplete wrong,
        // kany specific answer
        return [
            'No autopass, default question' => ['default', false, [
                0 => [1, 0, 0, 1, 1, 0, 1, 0],
                1 => [1, 0, 1, 1, 1, 0, 0, 1],
                2 => [1, 0, 1, 0, 0, 0, 0, 0],
                3 => [1, 0, 1, 0, 0, 0, 0, 1],
            ]],
            'Autopass on, default question' => ['default', true, [
                0 => [1, 1, 1, 1, 1, 1, 1, 1],
                1 => [1, 0, 1, 1, 1, 0, 0, 1],
                2 => [1, 1, 1, 1, 1, 1, 1, 1],
                3 => [1, 0, 1, 0, 0, 0, 0, 1],
            ]],
            'No autopass, nondefault question' => ['nondefault', false, [
                0 => [1, 0, 0, 1, 1, 0, 1, 0],
                1 => [1, 0, 1, 1, 1, 0, 0, 1],
                2 => [1, 0, 1, 0, 0, 0, 0, 0],
                3 => [1, 0, 1, 0, 0, 0, 0, 1],
            ]],
            'Autopass on, nondefault question' => ['nondefault', true, [
                0 => [1, 1, 1, 1, 1, 1, 1, 1],
                1 => [1, 0, 1, 1, 1, 0, 0, 1],
                2 => [1, 1, 1, 1, 1, 1, 1, 1],
                3 => [1, 0, 1, 0, 0, 0, 0, 1],
            ]],
            'No autopass, multipletwocorrect question' => ['multipletwocorrect', false, [
                0 => [1, 0, 0, 1, 1, 0, 1, 0],
                1 => [1, 0, 1, 0, 0, 0, 0, 1],
                2 => [1, 0, 1, 1, 0, 0, 0, 0],
                3 => [1, 0, 1, 0, 0, 0, 1, 1],
            ]],
            'Autopass on, multipletwocorrect question' => ['multipletwocorrect', true, [
                0 => [1, 1, 1, 1, 1, 1, 1, 1],
                1 => [1, 0, 1, 0, 0, 0, 0, 1],
                2 => [1, 1, 1, 1, 1, 1, 1, 1],
                3 => [1, 0, 1, 0, 0, 0, 1, 1],
            ]],
        ];
    }
}
