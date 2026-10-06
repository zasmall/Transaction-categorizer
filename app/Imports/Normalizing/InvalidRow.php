<?php

namespace App\Imports\Normalizing;

use RuntimeException;

/**
 * A single row can't be turned into a transaction. The import carries on without it,
 * and the message is shown to the user next to the row.
 */
class InvalidRow extends RuntimeException {}
