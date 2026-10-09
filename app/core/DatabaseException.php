<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Raised when the database cannot be reached or a query fails unexpectedly.
 *
 * The message is intentionally generic; detailed driver errors are written to
 * the logs by the Database class and never shown to end users.
 */
class DatabaseException extends RuntimeException
{
}
