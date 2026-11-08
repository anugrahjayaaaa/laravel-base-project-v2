<?php

namespace App\Http\Requests\V1\Member;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Validates the timeframe filter on the membership analytics screen (P18-F1).
 *
 * The window lives in the query string, not the session: a bookmarked
 * `?range=90d` must answer with the same window it shows. Authorization is the
 * route's `can:members.view`, same as every other member read.
 */
class MemberAnalyticsQueryRequest extends BaseFormRequest
{
    /** Preset ranges in days. A key here is also a cache key in MemberAnalyticsStatsAction. */
    public const PRESETS = ['7d' => 7, '30d' => 30, '90d' => 90, '1y' => 365];

    public function authorize(): bool
    {
        return $this->user()?->can('members.view') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'range' => ['nullable', Rule::in([...array_keys(self::PRESETS), 'custom'])],
            'date_from' => ['required_if:range,custom', 'nullable', 'date'],
            'date_to' => ['required_if:range,custom', 'nullable', 'date', 'after_or_equal:date_from'],
        ];
    }

    /**
     * The resolved window the Action queries. `custom` is validated to carry both
     * dates, so the only fall-through is a missing or unknown `range` → 30d.
     *
     * @return array{range: string, from: Carbon, to: Carbon}
     */
    public function window(): array
    {
        $range = (string) ($this->validated('range') ?? '30d');

        if ($range === 'custom') {
            return [
                'range' => $range,
                'from' => Carbon::parse($this->validated('date_from'))->startOfDay(),
                'to' => Carbon::parse($this->validated('date_to'))->endOfDay(),
            ];
        }

        return [
            'range' => $range,
            'from' => Carbon::now()->subDays(self::PRESETS[$range]),
            'to' => Carbon::now(),
        ];
    }
}
