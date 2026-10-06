<?php

namespace App\Imports\Parsing;

use RuntimeException;

/**
 * The file as a whole can't be imported (wrong format, missing columns, empty).
 * The message is safe to show to the user.
 */
class InvalidStatementFile extends RuntimeException {}
