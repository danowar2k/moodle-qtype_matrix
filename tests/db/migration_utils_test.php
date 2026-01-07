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

namespace qtype_matrix\db;

defined('MOODLE_INTERNAL') || die();

use advanced_testcase;
use Exception;

/**
 * Unit tests for the all grading class.
 */
class migration_utils_test extends advanced_testcase {

    public function test_extract_row_id():void {
        $this->assertEquals(123, migration_utils::extract_row_id('cell123'));
        $this->assertEquals(123, migration_utils::extract_row_id('cell123_234'));
        $this->assertEquals(0, migration_utils::extract_row_id('cell0_3'));
        $this->assertEquals(0, migration_utils::extract_row_id('_order'));
    }

    public function test_extract_col_id():void {
        $this->assertEquals(456, migration_utils::extract_col_id('cell123', '456'));
        $this->assertEquals(456, migration_utils::extract_col_id('cell0', '456'));
        $this->assertEquals(234, migration_utils::extract_col_id('cell123_234', '1'));
        $this->assertEquals(3, migration_utils::extract_col_id('cell0_3', '1'));
        $this->assertEquals(0, migration_utils::extract_col_id('_order', '1,2,3,4'));
    }

    public function test_to_new_name_and_value():void {
        $defaultorder = [4, 5, 6, 7];
        $defaultcols = [8, 9, 10, 11];
        $this->assertEquals(['row0', 0], migration_utils::to_new_name_and_value(
            'cell4', '8', $defaultorder, $defaultcols
        ));
    }

    /**
     * @dataProvider to_new_name_exception_data_provider
     * @return void
     */
    public function test_to_new_name_and_value_exceptions(
        $stepdataname,
        $stepdatavalue,
        $attemptorder,
        $cols,
        $expectedmessage
    ):void {
        $gotexception = false;
        try {
            migration_utils::to_new_name_and_value(
                $stepdataname, $stepdatavalue, $attemptorder, $cols
            );
        } catch (Exception $e) {
            $this->assertEquals($expectedmessage, $e->getMessage());
            $gotexception = true;
        }
        $this->assertTrue($gotexception);
    }

    public function to_new_name_exception_data_provider() {
        $defaultorder = [4,5,6,7];
        $defaultcols = [8,9,10,11];
        $goodcolid = '8';
        $badid = '12';
        $goodrowid = '4';
        return [
            'Missing rowid in name' => ['cell', $goodcolid, $defaultorder, $defaultcols, 'BLA'],
            'Single, bad data in name' => ['cellbad', $goodcolid, $defaultorder, $defaultcols, 'BLA'],
            'Single, no colid in value' => ['cell'.$goodrowid, 'bad', $defaultorder, $defaultcols, 'BLA'],
            'Multiple, missing colid in name' => ['cell'.$goodrowid.'_', $goodcolid, $defaultorder, $defaultcols, 'BLA'],
            'Multiple, bad data in name' => ['cell'.$goodrowid.'_bad', $goodcolid, $defaultorder, $defaultcols, 'BLA'],
            'No attemptorder' => ['cell'.$goodrowid, $goodcolid, [], $defaultcols, 'BLA'],
            'No cols' => ['cell'.$goodrowid, $goodcolid, $defaultorder, [], 'BLA'],
            'Name rowid not in order' => ['cell'.$badid, $goodcolid, $defaultorder, $defaultcols, 'BLUBB'],
            'Single, value colid not in cols' => ['cell'.$goodrowid, $badid, $defaultorder, $defaultcols, 'BLUBB'],
            'Multiple, name colid not in cols' => ['cell'.$goodrowid.'_'.$badid, '1', $defaultorder, $defaultcols, 'BLUBB'],
        ];
    }
}
