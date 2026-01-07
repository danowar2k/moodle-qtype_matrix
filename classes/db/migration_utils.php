<?php
/**
 * Author: Daniel Poggenpohl
 * Date: 06.01.2026
 */

namespace qtype_matrix\db;

use qtype_matrix\exception\broken_matrix_attempt_exception;

class migration_utils {

    public static function extract_row_id(string $stepdataname):int {
        $ismultiple = str_contains($stepdataname, '_');
        if (!str_contains($stepdataname, 'cell')) {
            return 0;
        }
        $nocellname = str_replace('cell', '', $stepdataname);
        if ($nocellname === '') {
            return 0;
        }
        $rowid = $ismultiple ? preg_replace('/_.*$/', '', $nocellname) : $nocellname;
        if (!preg_match('/^\d{1,}$/', $rowid)) {
            return 0;
        }
        return $rowid;
    }

    public static function extract_col_id(string $stepdataname, string $stepdatavalue):int {
        $ismultiple = str_contains($stepdataname, '_');
        if (!str_contains($stepdataname, 'cell')) {
            return 0;
        }
        $nocellname = str_replace('cell', '', $stepdataname);
        $colid = $ismultiple ? preg_replace('/^.*_/', '', $nocellname) : $stepdatavalue;
        if ($nocellname === '' || $colid === '') {
            return 0;
        }
        if (!preg_match('/^\d{1,}$/', $colid)) {
            return 0;
        }
        return $colid;
    }

    public static function to_new_name_and_value(
        string $oldstepdataname,
        string $oldstepdatavalue,
        array $attemptorder,
        array $colids
    ): array {
        $oldrowid = migration_utils::extract_row_id($oldstepdataname);
        $oldcolid = migration_utils::extract_col_id($oldstepdataname, $oldstepdatavalue);
        $ismultiple = str_contains($oldstepdataname, '_');
        if (!$attemptorder || !$oldrowid || !$oldcolid || !$colids) {
            throw new broken_matrix_attempt_exception();
        }
        $newrowindex = array_search($oldrowid, $attemptorder);
        $newcolindex = array_search($oldcolid, $colids);
        if ($newcolindex === false || $newrowindex === false) {
            throw new broken_matrix_attempt_exception();
        }
        $newname = 'row'.$newrowindex;
        if ($ismultiple) {
            $newname .= 'col'.$newcolindex;
        }
        $newvalue = $ismultiple ? $oldstepdatavalue : $newcolindex;
        return [$newname, $newvalue];
    }
}
