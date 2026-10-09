<?php

namespace App\Http\Requests\V1\Audit;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the audit viewer's list query parameters.
 *
 * ## Why `authorize()` is `true`
 *
 * Authorization is the route's job, not the request's. `web.php` carries
 * `->can('audit.view')` inside the `feature:activity_logs` group, and a Request
 * that also checked it would give two answers to one question — the failure
 * `AGENTS.md` names explicitly ("never in a controller constructor", and the
 * route gate is the source of truth). Every other `*QueryRequest` in
 * `app/Http/Requests/V1` is built the same way; read one before changing this.
 *
 * ## `sort` is an allowlist, and that is a security control
 *
 * `AuditIndexAction` passes this value to `orderBy()`, which does not bind its
 * second argument — it interpolates it as an identifier. Without `in:`, a
 * crafted `?sort=created_at;drop` reaches the query builder. The list here is
 * also the list of columns the table header offers, so it cannot drift into
 * being a second source of truth for what is sortable.
 *
 * ## `event` and `source` are free strings, deliberately
 *
 * They are allowlisted, and that is the point: `Auditable::audit()` accepts any
 * event name its caller passes, and pinning the list here would reject a
 * legitimate event rather than a malformed one. `max:255` still bounds the
 * parameter. The consequence is that filtering on an event nobody has written
 * returns an empty list rather than an error, which is the honest answer.
 */
class ActivityQueryRequest extends BaseFormRequest
{
    /**
     * Columns the viewer is allowed to order by.
     *
     * `created_at` and `id` only. The row's other meaningful columns —
     * `event`, `causer_id`, `subject_type` — are indexed separately from the
     * timestamp or not at all (see the Phase 10 breakdown, finding F2), so
     * offering them as sortable headers would advertise an index the database
     * does not have. `id` is the tiebreaker `created_at` needs on a table where
     * many rows share a timestamp under a bulk write.
     *
     * @var array<int, string>
     */
    public const SORTABLE = ['created_at', 'id'];

    /**
     * The route already answered "may this person read the audit trail".
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'event' => ['nullable', 'string', 'max:255'],
            'source' => ['nullable', 'string', 'max:32'],
            'causer_id' => ['nullable', 'integer', 'min:1'],
            'subject_type' => ['nullable', 'string', 'max:255'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'sort' => ['nullable', Rule::in(self::SORTABLE)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ];
    }

    /**
     * The validated filters as the action takes them.
     *
     * `$request->validated()` returns keys with nulls, and the action reads each
     * one with a `?:` default. Centralising the array here means the action, the
     * view's echoed-back filters and the export (Group E, same filters) all
     * agree on the key names without three hand-typed lists.
     *
     * @return array{
     *     search: string|null,
     *     event: string|null,
     *     source: string|null,
     *     causer_id: int|null,
     *     subject_type: string|null,
     *     date_from: string|null,
     *     date_to: string|null,
     *     sort: string,
     *     direction: string,
     *     per_page: int
     * }
     */
    public function filters(): array
    {
        $validated = $this->validated();

        return [
            'search' => $validated['search'] ?? null,
            'event' => $validated['event'] ?? null,
            'source' => $validated['source'] ?? null,
            'causer_id' => isset($validated['causer_id']) ? (int) $validated['causer_id'] : null,
            'subject_type' => $validated['subject_type'] ?? null,
            'date_from' => $validated['date_from'] ?? null,
            'date_to' => $validated['date_to'] ?? null,
            'sort' => $validated['sort'] ?? 'created_at',
            'direction' => $validated['direction'] ?? 'desc',
            'per_page' => (int) ($validated['per_page'] ?? 10),
        ];
    }
}
