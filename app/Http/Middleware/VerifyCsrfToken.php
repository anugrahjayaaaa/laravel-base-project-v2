<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

/**
 * CSRF, including under test.
 *
 * Laravel's own middleware skips the ENTIRE check when `runningUnitTests()` is
 * true — a convenience that makes every feature test a request no CSRF token
 * could satisfy, and therefore a test that never exercised the check at all.
 * The 2026-09-24 pentest saw precisely that: a web POST answered 302 instead of
 * 419, and the finding could only be filed as "uncertain" because the artifact
 * and the control were indistinguishable.
 *
 * Returning false here is what makes the suite honest: a missing token answers
 * 419 in tests exactly as it does in production, which is what
 * `SecurityAuditTest::test_web_state_changing_forms_require_csrf` pins.
 *
 * Do not delete this to make a failing test pass — the test is telling you the
 * form lost its `@csrf`.
 */
class VerifyCsrfToken extends Middleware
{
    protected $addHttpCookie = true;

    protected $except = [
        //
    ];

    protected function runningUnitTests(): bool
    {
        return false;
    }
}
