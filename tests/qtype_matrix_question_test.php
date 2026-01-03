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

namespace qtype_matrix;

use advanced_testcase;
use qtype_matrix\local\grading\all;
use qtype_matrix\local\grading\difference;
use qtype_matrix\local\grading\kany;
use qtype_matrix\local\grading\kprime;
use qtype_matrix\local\qtype_matrix_grading;
use qtype_matrix_question;
use question_attempt_step;
use question_classified_response;
use question_state;
use qtype_matrix_test_helper;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once $CFG->dirroot . '/question/engine/tests/helpers.php';
require_once $CFG->dirroot . '/question/engine/questionattempt.php';
require_once $CFG->dirroot . '/question/engine/questionattemptstep.php';
require_once $CFG->dirroot . '/question/type/matrix/question.php';
require_once $CFG->dirroot . '/question/type/matrix/tests/helper.php';

/**
 * Unit tests for the matrix question definition class.
 *
 */
class qtype_matrix_question_test extends advanced_testcase {

    public function test_response():void {
        $question = qtype_matrix_test_helper::make_question('nondefault');
        $response = [
        ];
        $this->assertFalse($question->response($response, 1, 2));
        $response = [
            'row1col2' => 1
        ];
        $this->assertTrue($question->response($response, 1, 2));
        $response = [
            'row0col1' => 1,
            'row1col2' => 1
        ];
        $this->assertTrue($question->response($response, 1, 2));
        $question->multiple = false;
        $response = [
        ];
        $this->assertFalse($question->response($response, 1, 2));
        $response = [
            'row1' => 1
        ];
        $this->assertFalse($question->response($response, 1, 2));
        $response = [
            'row1' => 2
        ];
        $this->assertTrue($question->response($response, 1, 2));
        $response = [
            'row0' => 2,
            'row1' => 2
        ];
        $this->assertTrue($question->response($response, 1, 2));
    }

    public function test_oldkey():void {
        $question = qtype_matrix_test_helper::make_question('nondefault');
        $this->assertEquals('cell1_2', $question->oldkey(1, 2));
        $question->multiple = false;
        $this->assertEquals('cell1', $question->oldkey(1, rand(2,100)));
    }

    public function test_newkey():void {
        $question = qtype_matrix_test_helper::make_question('nondefault');
        $this->assertEquals('row1col2', $question->newkey(1, 2));
        $question->multiple = false;
        $this->assertEquals('row1', $question->newkey(1, rand(2,100)));
    }

    /**
     * @return void
     */
    public function test_old_form_cell_name():void {
        $this->assertEquals('cell0_0',
            qtype_matrix_question::old_form_cell_name(0, 0, true));
        $this->assertEquals('cell1_2',
            qtype_matrix_question::old_form_cell_name(1, 2, true));

        $this->assertEquals('cell1',
            qtype_matrix_question::old_form_cell_name(1, 2, false));
        $this->assertEquals('cell355',
            qtype_matrix_question::old_form_cell_name(355, 123, false));
    }

    /**
     * @return void
     */
    public function test_new_form_cell_name():void {
        $this->assertEquals('row0col0',
            qtype_matrix_question::new_form_cell_name(0, 0, true));
        $this->assertEquals('row1col2',
            qtype_matrix_question::new_form_cell_name(1, 2, true));

        $this->assertEquals('row1',
            qtype_matrix_question::new_form_cell_name(1, 2, false));
        $this->assertEquals('row355',
            qtype_matrix_question::new_form_cell_name(355, 123, false));
    }

    public function test_answer():void {
        $question = qtype_matrix_test_helper::make_question('default');
        $this->initialize_order($question);
        $question->weights[5][10] = 1;
        $this->assertTrue($question->answer(1, 2));
        $question->weights[5][10] = 0;
        $this->assertFalse($question->answer(1, 2));
    }

    public function test_weight():void {
        $question = qtype_matrix_test_helper::make_question('default');
        $question->weights[5][10] = 1;
        $this->assertEquals(1, $question->weight(5, 10));
        // Strangely, this is bad data, but works here.
        $question->weights[5][10] = 2;
        $this->assertEquals(2, $question->weight(5, 10));
    }

    public function test_start_attempt_noshuffle():void {
        // FIXME: Don't test usedndui for now
        $qa = new question_attempt_step();
        $question = qtype_matrix_test_helper::make_question('default');
        $question->shuffleanswers = false;
        $normalrows = [
            4 => 'first',
            5 => 'second',
            6 => 'third',
            7 => 'fourth'
        ];
        $rowids = array_keys($normalrows);
        $question->rows = $normalrows;
        $question->start_attempt($qa, 1);
        $this->assertEquals($rowids, explode(',', $qa->get_qt_var($question::KEY_ROWS_ORDER)));
    }

    public function test_start_attempt_shuffle():void {
        $qa = new question_attempt_step();
        $question = qtype_matrix_test_helper::make_question('default');
        $question->shuffleanswers = true;
        $normalrows = [
            4 => 'first',
            5 => 'second',
            6 => 'third',
            7 => 'fourth'
        ];
        $rowids = array_keys($normalrows);
        $question->rows = $normalrows;
        $question->start_attempt($qa, 1);
        $shuffledids = explode(',', $qa->get_qt_var($question::KEY_ROWS_ORDER));
        foreach ($shuffledids as $shuffledid) {
            $this->assertContainsEquals($shuffledid, $rowids);
        }
        foreach ($rowids as $rowid) {
            $this->assertContainsEquals($rowid, $shuffledids);
        }
    }

    public function test_shuffle_answers():void {
        $question = qtype_matrix_test_helper::make_question('default');
        $question->shuffleanswers = true;
        $this->assertTrue($question->shuffle_answers());
        $question->shuffleanswers = false;
        $this->assertFalse($question->shuffle_answers());
        // FIXME: $PAGE->cm influences this, should be mocked for full testing
    }

    public function test_shuffle_authorized():void {
        $question = qtype_matrix_test_helper::make_question('default');
        $this->assertTrue($question->shuffle_authorized());
        // FIXME: $PAGE->cm also influences this, should be mocked for full testing
    }

    public function test_apply_attempt_state():void {
        $qa = new question_attempt_step();
        $question = qtype_matrix_test_helper::make_question('default');
        $question->shuffleanswers = true;
        $normalrows = [
            4 => 'first',
            5 => 'second',
            6 => 'third',
            7 => 'fourth'
        ];
        $question->rows = $normalrows;
        // TODO: We should probably not need to use Reflection...
        $questionClass = new \ReflectionClass($question);
        $orderProperty = $questionClass->getProperty('order');
        $this->assertNull($orderProperty->getValue($question));
        $this->assertNull($qa->get_qt_var($question::KEY_ROWS_ORDER));
        $question->apply_attempt_state($qa);
        $questionClass = new \ReflectionClass($question);
        $orderProperty = $questionClass->getProperty('order');

        $this->assertNotNull($orderProperty->getValue($question));
        $this->assertEquals(implode(',', $orderProperty->getValue($question)), $qa->get_qt_var($question::KEY_ROWS_ORDER));
        $question->apply_attempt_state($qa);
        $this->assertNotNull($orderProperty->getValue($question));
        $this->assertEquals(implode(',', $orderProperty->getValue($question)), $qa->get_qt_var($question::KEY_ROWS_ORDER));
    }

    public function test_get_order():void {
        $question = qtype_matrix_test_helper::make_question('default');
        $qa = new question_attempt_step();
        // In a normal process, each attempt will always have the first step initialized
        $qa->set_qt_var($question::KEY_ROWS_ORDER, '4,5,6,7');
        $mockedAttempt = $this->createStub('question_attempt');
        $mockedAttempt->method('get_step')->willReturn($qa);
        $order = $question->get_order($mockedAttempt);
        $this->assertEquals([4,5,6,7], $order);
        $qa->set_qt_var($question::KEY_ROWS_ORDER, '7,6,5,4');
        $order = $question->get_order($mockedAttempt);
        $this->assertEquals([4,5,6,7], $order);
        $question = qtype_matrix_test_helper::make_question('default');
        $qa->set_qt_var($question::KEY_ROWS_ORDER, '7,6,5,4');
        $order = $question->get_order($mockedAttempt);
        $this->assertEquals([7,6,5,4], $order);
    }

// FIXME: This doesn't need to be tested as long as Matrix questions don't let the user save question hints.
//    public function test_compute_final_grade():void {
//        $question = qtype_matrix_test_helper::make_question('default');
//    }

    /**
     * @dataProvider grade_response_data_provider
     * @covers ::get_expected_data
     * @return void
     */
    public function test_grade_response(
        string $questiontype,
        string $grademethod,
        float  $correctgrade,
        float  $incorrectgrade,
        float  $onerowwronggrade,
        float  $halfcorrectgrade,
        float  $incompletepartiallycorrectgrade,
        float  $incompleteincorrectgrade
    ): void {
        $question = qtype_matrix_test_helper::make_question($questiontype);
        $this->initialize_order($question);
        $question->grademethod = $grademethod;

        $correctanswer = self::make_correct_answer($question);
        $grade = $question->grade_response($correctanswer);
        $this->assertEquals($this->get_expected_grade($correctgrade), $grade);

        $incorrectanswer = self::make_incorrect_answer($question);
        $grade = $question->grade_response($incorrectanswer);
        $this->assertEquals($this->get_expected_grade($incorrectgrade), $grade);

        $onerowwronganswer = self::make_one_row_wrong_answer($question);
        $grade = $question->grade_response($onerowwronganswer);
        $this->assertEquals($this->get_expected_grade($onerowwronggrade), $grade);

        $halfcorrectanswer = self::make_half_correct_answer($question);
        $grade = $question->grade_response($halfcorrectanswer);
        $this->assertEquals($this->get_expected_grade($halfcorrectgrade), $grade);

        $incompletepartiallycorrectanswer = self::make_incomplete_partially_correct_answer($question);
        $grade = $question->grade_response($incompletepartiallycorrectanswer);
        $this->assertEquals($this->get_expected_grade($incompletepartiallycorrectgrade), $grade);

        $incompletewronganswer = self::make_incomplete_wrong_answer($question);
        $grade = $question->grade_response($incompletewronganswer);
        $this->assertEquals($this->get_expected_grade($incompleteincorrectgrade), $grade);
    }

    private function get_expected_grade(float $gradenumber):array {
        if ($gradenumber == 0) {
            $state = question_state::$gradedwrong;
        } else if ($gradenumber == 1) {
            $state = question_state::$gradedright;
        } else {
            $state = question_state::$gradedpartial;
        }
        return [$gradenumber, $state];
    }
    /**
     * Provides data for test_grade_response().
     *
     * @return array of data for function
     */
    public static function grade_response_data_provider(): array {
        // correct, one row wrong, incorrect, half correct, incomplete partially correct, incomplete wrong
        return [
            'Default question, kprime grading' => ['default', kprime::get_name(), 1, 0, 0, 0, 0, 0],
            'Default question, kany grading' => ['default', kany::get_name(), 1, 0, 0.5, 0, 0, 0],
            'Default question, all grading' => ['default', all::get_name(), 1, 0, 0.75, 0.5, 0.5, 0],
            'Default question, difference grading' => ['nondefault', difference::get_name(), 1, 0, 0.75, 0.5, 0.5, 0],
            'Nondefault question, kprime grading' => ['nondefault', kprime::get_name(), 1, 0, 0, 0, 0, 0],
            'Nondefault question, kany grading' => ['nondefault', kany::get_name(), 1, 0, 0.5, 0, 0, 0],
            'Nondefault question, all grading' => ['nondefault', all::get_name(), 1, 0, 0.75, 0.5, 0.5, 0],
            'Nondefault question, difference grading' => ['nondefault', difference::get_name(), 1, 0, 0.75, 0.5, 0.5, 0],
            'multipletwocorrect question, kprime grading' => ['multipletwocorrect', kprime::get_name(), 1, 0, 0, 0, 0, 0],
            'multipletwocorrect question, kany grading' => ['multipletwocorrect', kany::get_name(), 1, 0, 0.5, 0, 0, 0],
            'multipletwocorrect question, all grading' => ['multipletwocorrect', all::get_name(), 1, 0, 0.75, 0.25, 0.25, 0],
            'multipletwocorrect question, difference grading' => [
                'multipletwocorrect',
                difference::get_name(),
                1,
                0,
                0.75,
                0.6944444444444444,
                0.4722222222222222,
                0
            ],
        ];
    }
    /**
     * @covers ::get_expected_data
     * @return void
     */
    public function test_is_complete_response(): void {
        $question = qtype_matrix_test_helper::make_question('default');
        $this->initialize_order($question);
        $answer = [];
        $this->assertFalse($question->is_complete_response($answer));
        $this->assertNotNull($question->get_validation_error($answer));

        $answer = self::make_correct_answer($question);
        $this->assertTrue($question->is_complete_response($answer));
        $this->assertNull($question->get_validation_error($answer));

        $answer = self::make_incorrect_answer($question);
        $this->assertTrue($question->is_complete_response($answer));
        $this->assertNull($question->get_validation_error($answer));

        $answer = self::make_one_row_wrong_answer($question);
        $this->assertTrue($question->is_complete_response($answer));
        $this->assertNull($question->get_validation_error($answer));

        $answer = self::make_half_correct_answer($question);
        $this->assertTrue($question->is_complete_response($answer));
        $this->assertNull($question->get_validation_error($answer));

        $answer = self::make_incomplete_partially_correct_answer($question);
        $this->assertFalse($question->is_complete_response($answer));
        $this->assertNotNull($question->get_validation_error($answer));

        $answer = self::make_incomplete_wrong_answer($question);
        $this->assertFalse($question->is_complete_response($answer));
        $this->assertNotNull($question->get_validation_error($answer));

        $question = qtype_matrix_test_helper::make_question('nondefault');
        $this->initialize_order($question);

        $answer = [];
        $this->assertTrue($question->is_complete_response($answer));

        $answer = self::make_correct_answer($question);
        $this->assertTrue($question->is_complete_response($answer));

        $answer = self::make_incorrect_answer($question);
        $this->assertTrue($question->is_complete_response($answer));

        $answer = self::make_one_row_wrong_answer($question);
        $this->assertTrue($question->is_complete_response($answer));

        $answer = self::make_half_correct_answer($question);
        $this->assertTrue($question->is_complete_response($answer));

        $answer = self::make_incomplete_partially_correct_answer($question);
        $this->assertTrue($question->is_complete_response($answer));

        $answer = self::make_incomplete_wrong_answer($question);
        $this->assertTrue($question->is_complete_response($answer));

    }

    public function test_get_num_selected_choices():void {
        $question = qtype_matrix_test_helper::make_question('default');

        $answer = self::make_correct_answer($question);
        $this->assertEquals(count($question->rows), $question->get_num_selected_choices($answer));
        $answer = self::make_incorrect_answer($question);
        $this->assertEquals(count($question->rows), $question->get_num_selected_choices($answer));
        $answer = self::make_one_row_wrong_answer($question);
        $this->assertEquals(count($question->rows), $question->get_num_selected_choices($answer));
        $answer = self::make_half_correct_answer($question);
        $this->assertEquals(count($question->rows), $question->get_num_selected_choices($answer));
        $answer = self::make_incomplete_partially_correct_answer($question);
        $this->assertEquals(count($question->rows) - 2, $question->get_num_selected_choices($answer));
        $answer = self::make_incomplete_wrong_answer($question);
        $this->assertEquals(count($question->rows) - 2, $question->get_num_selected_choices($answer));

        $question = qtype_matrix_test_helper::make_question('multipletwocorrect');
        $answer = self::make_correct_answer($question);
        $this->assertEquals(8, $question->get_num_selected_choices($answer));
        $answer = self::make_incorrect_answer($question);
        $this->assertEquals(4, $question->get_num_selected_choices($answer));
        $answer = self::make_one_row_wrong_answer($question);
        $this->assertEquals(7, $question->get_num_selected_choices($answer));
        $answer = self::make_half_correct_answer($question);
        $this->assertEquals(6, $question->get_num_selected_choices($answer));
        $answer = self::make_incomplete_partially_correct_answer($question);
        $this->assertEquals(4, $question->get_num_selected_choices($answer));
        $answer = self::make_incomplete_wrong_answer($question);
        $this->assertEquals(2, $question->get_num_selected_choices($answer));
    }

    public function test_is_gradable_response():void {
        $question = qtype_matrix_test_helper::make_question('default');
        foreach (qtype_matrix_grading::VALID_GRADINGS as $validgrading) {
            $question->grademethod = $validgrading;
            $answer = self::make_correct_answer($question);
            $this->assertTrue($question->is_gradable_response($answer));
            $answer = self::make_incorrect_answer($question);
            $this->assertTrue($question->is_gradable_response($answer));
            $answer = self::make_one_row_wrong_answer($question);
            $this->assertTrue($question->is_gradable_response($answer));
            $answer = self::make_half_correct_answer($question);
            $this->assertTrue($question->is_gradable_response($answer));
            $answer = self::make_incomplete_partially_correct_answer($question);
            $this->assertTrue($question->is_gradable_response($answer));
            $answer = self::make_incomplete_wrong_answer($question);
            $this->assertTrue($question->is_gradable_response($answer));
            $answer = [];
            $this->assertFalse($question->is_gradable_response($answer));
        }
    }

    /**
     * @covers ::get_expected_data
     * @return void
     */
    public function test_summarise_response(): void {
        $question = qtype_matrix_test_helper::make_question('default');
        $order = $this->initialize_order($question);

        $answer = self::make_correct_answer($question);
        $summary = $question->summarise_response($answer);
        $this->check_summary($question, $order, $answer, $summary);

        $answer = self::make_incomplete_wrong_answer($question);
        $summary = $question->summarise_response($answer);
        $this->check_summary($question, $order, $answer, $summary);

        $question = qtype_matrix_test_helper::make_question('nondefault');
        $order = $this->initialize_order($question);

        $answer = self::make_correct_answer($question);
        $summary = $question->summarise_response($answer);
        $this->check_summary($question, $order, $answer, $summary);
    }

    private function check_summary(qtype_matrix_question $question, array $order, array $answer, string $summary):void {
        $indicedcols = array_keys($question->cols);
        foreach ($order as $rowindex => $rowid) {
            $row = $question->rows[$rowid];
            $key = $question->multiple ? '' : $question->key($rowindex);
            $shouldcolids = [];
            foreach ($indicedcols as $colindex => $colid) {
                $key = $question->multiple ? $question->key($rowindex, $colindex) : $key;
                if (isset($answer[$key])) {
                    $shouldcolids[] = $question->multiple ? $colid : $indicedcols[$answer[$key]];
                }
            }
            $shouldcolids = array_unique($shouldcolids);
            foreach ($shouldcolids as $shouldcolid) {
                $this->assertStringContainsString(
                    $row->shorttext . ': ' . $question->cols[$shouldcolid]->shorttext, $summary
                );
            }
            $notcolids = array_diff($indicedcols, $shouldcolids);
            foreach ($notcolids as $notcolid) {
                $this->assertStringNotContainsString($row->shorttext.': '.$question->cols[$notcolid]->shorttext, $summary);
            }
        }
    }
    /**
     * @covers ::get_expected_data
     * @return void
     */
    public function test_is_same_response(): void {
        $question = qtype_matrix_test_helper::make_question('default');
        $this->initialize_order($question);

        $correct = $question->get_correct_response();
        $answer = self::make_correct_answer($question);
        $this->assertTrue($question->is_same_response($correct, $answer));

        $answer = self::make_incorrect_answer($question);
        $this->assertFalse($question->is_same_response($correct, $answer));

        $nextanswer = $answer;
        unset($nextanswer['row3']);
        $this->assertFalse($question->is_same_response($answer, $nextanswer));

        $nextanswer = $answer;
        $nextanswer['row3'] = $nextanswer['row3'] - 1;
        $this->assertFalse($question->is_same_response($answer, $nextanswer));

        $question = qtype_matrix_test_helper::make_question('nondefault');
        $this->initialize_order($question);

        $correct = $question->get_correct_response();
        $answer = self::make_correct_answer($question);
        $this->assertTrue($question->is_same_response($correct, $answer));

        $answer = self::make_incorrect_answer($question);
        $this->assertFalse($question->is_same_response($correct, $answer));

        $nextanswer = $answer;
        unset($nextanswer['row3col3']);
        $this->assertFalse($question->is_same_response($answer, $nextanswer));

        $nextanswer = $answer;
        unset($nextanswer['row3col3']);
        $nextanswer['row3col2'] = true;
        $this->assertFalse($question->is_same_response($answer, $nextanswer));

        $nextanswer = $answer;
        $nextanswer['row3col3'] = false;
        $this->assertFalse($question->is_same_response($answer, $nextanswer));

    }

    /**
     * @covers ::get_expected_data
     * @return void
     */
    public function test_get_correct_response():void {
        $question = qtype_matrix_test_helper::make_question('default');
        $this->initialize_order($question);

        $answer = self::make_correct_answer($question);
        $this->assertEquals($answer, $question->get_correct_response());

        $answer = self::make_incorrect_answer($question);
        $this->assertNotEquals($answer, $question->get_correct_response());

        $question = qtype_matrix_test_helper::make_question('nondefault');
        $this->initialize_order($question);

        $answer = self::make_correct_answer($question);
        $this->assertEquals($answer, $question->get_correct_response());

        $answer = self::make_incorrect_answer($question);
        $this->assertNotEquals($answer, $question->get_correct_response());
    }

    public function test_get_expected_data():void {
        $question = qtype_matrix_test_helper::make_question('default');
        $this->initialize_order($question);
        $expected = array_fill_keys([
            'row0',
            'row1',
            'row2',
            'row3'
        ], PARAM_INT
        );
        $this->assertEquals($expected, $question->get_expected_data());

        $question = qtype_matrix_test_helper::make_question('nondefault');
        $this->initialize_order($question);
        $expected = array_fill_keys([
            'row0col0','row0col1','row0col2','row0col3',
            'row1col0','row1col1','row1col2','row1col3',
            'row2col0','row2col1','row2col2','row2col3',
            'row3col0','row3col1','row3col2','row3col3',
            ],
            PARAM_BOOL
        );
        $this->assertEquals($expected, $question->get_expected_data());
    }

    public function test_classify_response():void {
        $question = qtype_matrix_test_helper::make_question('default');
        $this->initialize_order($question);
        $answer = self::make_correct_answer($question);
        $classifiedresponse = [
            4 => new question_classified_response(9, $question->cols[9]->shorttext, 1),
            5 => new question_classified_response(9, $question->cols[9]->shorttext, 1),
            6 => new question_classified_response(9, $question->cols[9]->shorttext, 1),
            7 => new question_classified_response(9, $question->cols[9]->shorttext, 1)
        ];
        $this->assertEquals($classifiedresponse, $question->classify_response($answer));

        $answer = self::make_incorrect_answer($question);
        $classifiedresponse = [
            4 => new question_classified_response(11, $question->cols[11]->shorttext, 0),
            5 => new question_classified_response(11, $question->cols[11]->shorttext, 0),
            6 => new question_classified_response(11, $question->cols[11]->shorttext, 0),
            7 => new question_classified_response(11, $question->cols[11]->shorttext, 0)
        ];
        $this->assertEquals($classifiedresponse, $question->classify_response($answer));

        $answer = self::make_one_row_wrong_answer($question);
        $classifiedresponse = [
            4 => new question_classified_response(11, $question->cols[11]->shorttext, 0),
            5 => new question_classified_response(9, $question->cols[9]->shorttext, 1),
            6 => new question_classified_response(9, $question->cols[9]->shorttext, 1),
            7 => new question_classified_response(9, $question->cols[9]->shorttext, 1)
        ];
        $this->assertEquals($classifiedresponse, $question->classify_response($answer));

        $answer = self::make_half_correct_answer($question);
        $classifiedresponse = [
            4 => new question_classified_response(9, $question->cols[9]->shorttext, 1),
            5 => new question_classified_response(9, $question->cols[9]->shorttext, 1),
            6 => new question_classified_response(11, $question->cols[11]->shorttext, 0),
            7 => new question_classified_response(11, $question->cols[11]->shorttext, 0)
        ];
        $this->assertEquals($classifiedresponse, $question->classify_response($answer));

        $answer = self::make_incomplete_partially_correct_answer($question);
        $classifiedresponse = [
            4 => new question_classified_response(9, $question->cols[9]->shorttext, 1),
            5 => new question_classified_response(9, $question->cols[9]->shorttext, 1),
            6 => question_classified_response::no_response(),
            7 => question_classified_response::no_response()
        ];
        $this->assertEquals($classifiedresponse, $question->classify_response($answer));

        $answer = self::make_incomplete_wrong_answer($question);
        $classifiedresponse = [
            4 => new question_classified_response(11, $question->cols[11]->shorttext, 0),
            5 => new question_classified_response(11, $question->cols[11]->shorttext, 0),
            6 => question_classified_response::no_response(),
            7 => question_classified_response::no_response()
        ];
        $this->assertEquals($classifiedresponse, $question->classify_response($answer));

        $question = qtype_matrix_test_helper::make_question('nondefault');
        $this->initialize_order($question);
        $answer = self::make_correct_answer($question);
        $classifiedresponse = [
            4 => [9 => new question_classified_response(9, $question->cols[9]->shorttext, 0.25)],
            5 => [9 => new question_classified_response(9, $question->cols[9]->shorttext, 0.25)],
            6 => [9 => new question_classified_response(9, $question->cols[9]->shorttext, 0.25)],
            7 => [9 => new question_classified_response(9, $question->cols[9]->shorttext, 0.25)]
        ];
        $this->assertEquals($classifiedresponse, $question->classify_response($answer));

        $answer = self::make_incorrect_answer($question);
        $classifiedresponse = [
            4 => [11 => new question_classified_response(11, $question->cols[11]->shorttext, 0)],
            5 => [11 => new question_classified_response(11, $question->cols[11]->shorttext, 0)],
            6 => [11 => new question_classified_response(11, $question->cols[11]->shorttext, 0)],
            7 => [11 => new question_classified_response(11, $question->cols[11]->shorttext, 0)]
        ];
        $this->assertEquals($classifiedresponse, $question->classify_response($answer));

        $answer = self::make_one_row_wrong_answer($question);
        $classifiedresponse = [
            4 => [11 => new question_classified_response(11, $question->cols[11]->shorttext, 0)],
            5 => [9 => new question_classified_response(9, $question->cols[9]->shorttext, 0.25)],
            6 => [9 => new question_classified_response(9, $question->cols[9]->shorttext, 0.25)],
            7 => [9 => new question_classified_response(9, $question->cols[9]->shorttext, 0.25)]
        ];
        $this->assertEquals($classifiedresponse, $question->classify_response($answer));

        $answer = self::make_half_correct_answer($question);
        $classifiedresponse = [
            4 => [9 => new question_classified_response(9, $question->cols[9]->shorttext, 0.25)],
            5 => [9 => new question_classified_response(9, $question->cols[9]->shorttext, 0.25)],
            6 => [11 => new question_classified_response(11, $question->cols[11]->shorttext, 0)],
            7 => [11 => new question_classified_response(11, $question->cols[11]->shorttext, 0)]
        ];
        $this->assertEquals($classifiedresponse, $question->classify_response($answer));

        $answer = self::make_incomplete_partially_correct_answer($question);
        $classifiedresponse = [
            4 => [9 => new question_classified_response(9, $question->cols[9]->shorttext, 0.25)],
            5 => [9 => new question_classified_response(9, $question->cols[9]->shorttext, 0.25)],
            6 => question_classified_response::no_response(),
            7 => question_classified_response::no_response()
        ];
        $this->assertEquals($classifiedresponse, $question->classify_response($answer));

        $answer = self::make_incomplete_wrong_answer($question);
        $classifiedresponse = [
            4 => [11 => new question_classified_response(11, $question->cols[11]->shorttext, 0)],
            5 => [11 => new question_classified_response(11, $question->cols[11]->shorttext, 0)],
            6 => question_classified_response::no_response(),
            7 => question_classified_response::no_response()
        ];
        $this->assertEquals($classifiedresponse, $question->classify_response($answer));
    }

    private function initialize_order(qtype_matrix_question $question):array {
        $qa = new question_attempt_step();
        $qa->set_qt_var($question::KEY_ROWS_ORDER, '4,5,6,7');
        $mockedAttempt = $this->createStub('question_attempt');
        $mockedAttempt->method('get_step')->willReturn($qa);
        return $question->get_order($mockedAttempt);
    }

    /**
     * @param qtype_matrix_question $question
     * @return array
     */
    protected static function make_correct_answer(qtype_matrix_question $question): array {
        $answermatrix = [];
        switch ($question->questiontext) {
            case 'default':
            case 'nondefault':
                $answermatrix[0] = [0,1,0,0];
                $answermatrix[1] = [0,1,0,0];
                $answermatrix[2] = [0,1,0,0];
                $answermatrix[3] = [0,1,0,0];
                break;
            case 'multipletwocorrect':
                $answermatrix[0] = [1,1,0,0];
                $answermatrix[1] = [1,1,0,0];
                $answermatrix[2] = [1,1,0,0];
                $answermatrix[3] = [1,1,0,0];
                break;
        }
        return self::build_answer_with_matrix($question, $answermatrix);
    }

    /**
     * @param qtype_matrix_question $question
     * @return array
     */
    protected static function make_incorrect_answer(qtype_matrix_question $question): array {
        $answermatrix = [];
        $answermatrix[0] = [0,0,0,1];
        $answermatrix[1] = [0,0,0,1];
        $answermatrix[2] = [0,0,0,1];
        $answermatrix[3] = [0,0,0,1];
        return self::build_answer_with_matrix($question, $answermatrix);
    }

    /**
     * @param qtype_matrix_question $question
     * @return array
     */
    protected static function make_one_row_wrong_answer(qtype_matrix_question $question): array {
        $answermatrix = [];
        switch ($question->questiontext) {
            case 'default':
            case 'nondefault':
                $answermatrix[0] = [0,0,0,1];
                $answermatrix[1] = [0,1,0,0];
                $answermatrix[2] = [0,1,0,0];
                $answermatrix[3] = [0,1,0,0];
                break;
            case 'multipletwocorrect':
                $answermatrix[0] = [0,0,0,1];
                $answermatrix[1] = [1,1,0,0];
                $answermatrix[2] = [1,1,0,0];
                $answermatrix[3] = [1,1,0,0];
                break;
        }
        return self::build_answer_with_matrix($question, $answermatrix);
    }

    /**
     * Produces a partially correct answer, all possible variations.
     * @param qtype_matrix_question $question
     * @return array
     */
    protected static function make_half_correct_answer(qtype_matrix_question $question): array {
        $answermatrix = [];
        switch ($question->questiontext) {
            case 'default':
            case 'nondefault':
                $answermatrix[0] = [0,1,0,0];
                $answermatrix[1] = [0,1,0,0];
                $answermatrix[2] = [0,0,0,1];
                $answermatrix[3] = [0,0,0,1];
                break;
            case 'multipletwocorrect':
                $answermatrix[0] = [1,1,0,0];
                $answermatrix[1] = [0,1,0,1];
                $answermatrix[2] = [0,1,0,0];
                $answermatrix[3] = [0,0,0,1];
                break;
        }
        return self::build_answer_with_matrix($question, $answermatrix);
    }

    /**
     * @param qtype_matrix_question $question
     * @return array
     */
    protected static function make_incomplete_partially_correct_answer(qtype_matrix_question $question): array {
        $answermatrix = [];
        switch ($question->questiontext) {
            case 'default':
            case 'nondefault':
                $answermatrix[0] = [0,1,0,0];
                $answermatrix[1] = [0,1,0,0];
                $answermatrix[2] = [0,0,0,0];
                $answermatrix[3] = [0,0,0,0];
                break;
            case 'multipletwocorrect':
                $answermatrix[0] = [1,1,0,0];
                $answermatrix[1] = [0,1,0,1];
                $answermatrix[2] = [0,0,0,0];
                $answermatrix[3] = [0,0,0,0];
                break;
        }
        return self::build_answer_with_matrix($question, $answermatrix);
    }

    /**
     * @param qtype_matrix_question $question
     * @return array
     */
    protected static function make_incomplete_wrong_answer(qtype_matrix_question $question): array {
        $answermatrix = [];
        $answermatrix[0] = [0,0,0,1];
        $answermatrix[1] = [0,0,0,1];
        $answermatrix[2] = [0,0,0,0];
        $answermatrix[3] = [0,0,0,0];
        return self::build_answer_with_matrix($question, $answermatrix);
    }

    private static function build_answer_with_matrix(qtype_matrix_question $question, array $matrix):array {
        $answer = [];
        foreach ($matrix as $rowindex => $cols) {
            foreach ($cols as $colindex => $colvalue) {
                if ($colvalue > 0) {
                    $key = $question->key($rowindex, $colindex);
                    $answer[$key] = $question->multiple ? true : $colindex;
                }
            }
        }
        return $answer;
    }
}
