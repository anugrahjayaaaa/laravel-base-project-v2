<?php

namespace App\Exceptions;

use Exception;

/**
 * A role change would have left the app with no superadmin.
 *
 * Not a validation error: the payload is well-formed, it is just the one
 * combination that must not be applied. Rendered in bootstrap/app.php as a 409
 * for JSON and a redirect-back for web, because the two callers need different
 * things — an API client needs the status code, a form needs its input back.
 */
class LastSuperadminException extends Exception
{
    public function __construct(
        string $message = 'The last superadmin cannot have their superadmin role removed.',
    ) {
        parent::__construct($message);
    }
}
