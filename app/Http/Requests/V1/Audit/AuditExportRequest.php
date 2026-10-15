<?php

namespace App\Http\Requests\V1\Audit;

use Illuminate\Validation\Rule;

/**
 * Validates an export request (Phase 10, P10-E2).
 *
 * ## Why this extends `ActivityQueryRequest` instead of repeating its rules
 *
 * The plan for this task said to "extract the rule array to a shared place both
 * read, rather than copy-pasting 9 lines that drift" — which is what inheritance
 * is. `rules()` below adds one key to the parent's, `filters()` is inherited
 * whole, and there is no second list of filter names to fall behind. A shared
 * const array would have needed a third caller to agree on which keys the export
 * actually honours.
 *
 * ## `per_page` is accepted and deliberately ignored
 *
 * The parent validates it because it is the page's parameter, and the export has
 * no pages: the job streams the whole filtered set. Validating it costs one rule
 * and keeps the key names identical between the two, which is worth more than
 * rejecting a stray `per_page` that the caller could only have copied from the
 * URL bar.
 *
 * ## `format` is an allowlist of one, not a free string
 *
 * `Rule::in(['csv'])` exists so the day XLSX ships this rule changes in one place.
 * A free-form `format` string would reach the job, and the job would branch on an
 * attacker-supplied value to decide which writer to call.
 */
class AuditExportRequest extends ActivityQueryRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'format' => ['nullable', Rule::in(['csv'])],
        ]);
    }

    /**
     * The filters the job stores on the row.
     *
     * `format` is dropped: it is a single supported value today, and storing it on
     * every row would be a column that is always `csv` and never read.
     *
     * @return array<string, mixed>
     */
    public function exportFilters(): array
    {
        $filters = $this->filters();

        unset($filters['per_page']);

        return $filters;
    }
}
