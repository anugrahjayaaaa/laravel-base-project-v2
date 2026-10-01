<?php

namespace App\Http\Requests;

use App\Concerns\FormatsApiErrors;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Base for every Form Request in the application.
 *
 * Owning the error contract here rather than in each Request means an endpoint
 * cannot forget it: the four Auth requests that used `FormatsApiErrors`
 * individually and the seventeen that did not returned two different 422 shapes
 * — `{message, errors, code, meta}` against Laravel's default `{message,
 * errors}`. Both are now `{message, errors, code, meta}`.
 *
 * `message` and `errors` are unchanged, so clients reading the original keys
 * are unaffected; `code` and `meta` are additive.
 *
 * `meta.request_id` and `meta.timestamp` mirror what `Controller::respond()`
 * emits on success, so a failure and its neighbouring success share one shape.
 */
abstract class BaseFormRequest extends FormRequest
{
    use FormatsApiErrors;
}
