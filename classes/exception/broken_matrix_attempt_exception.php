<?php
/**
 * Author: Daniel Poggenpohl
 * Date: 06.01.2026
 */

namespace qtype_matrix\exception;

use Exception;

class broken_matrix_attempt_exception extends Exception {

    public function __construct() {
        parent::__construct('qtype_matrix question attempt contains bad data and cannot be migrated');
    }
}
