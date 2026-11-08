<?php

namespace App\Actions\V1\Member;

use App\Http\Requests\V1\Member\MemberAnalyticsQueryRequest;
use App\Models\Member;
use App\Models\MemberSubscription;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Membership analytics view model (P18-F2): growth, retention and lifecycle risk.
 *
 * Non-financial only — money is Phase 20's dashboard. Nothing here writes a row.
 *
 * Two rules shape it:
 * - one conditional aggregate per metric instead of one per bucket, so a twelve-
 *   bucket year is still two statements (F2's "no N+1");
 * - the payload is nested arrays of strings and ints only. `AGENTS.md` § Traps: a
 *   Collection / model / Carbon cached through the Redis store comes back
 *   `__PHP_Incomplete_Class` on a hit, and a dashboard rendering `1` in every
 *   card looks exactly like a database holding one member.
 *
 * Column names are interpolated into `SUM(CASE …)` from private literals of this
 * class, never from request input; every value is a bound placeholder.
 */
class MemberAnalyticsStatsAction
{
    /**
     * Bounds how long a MISSED invalidation can be read. Writes bust the preset
     * keys through `MemberAnalyticsObserver`, so this is the safety net and not
     * the freshness policy — the same shape as `UserIndexAction::TTL`.
     */
    private const TTL = 300;

    /** Members with no recorded sign-in for this long count as at risk. */
    private const DORMANT_DAYS = 30;

    /** Rows on the at-risk list; the card shows the full count. */
    private const AT_RISK_ROWS = 20;

    /** @var list<array{label: string, months: ?int}> */
    private const TENURE_BANDS = [
        ['label' => 'Under 1 month', 'months' => 1],
        ['label' => '1 – 3 months', 'months' => 3],
        ['label' => '3 – 6 months', 'months' => 6],
        ['label' => '6 – 12 months', 'months' => 12],
        ['label' => 'Over 1 year', 'months' => null],
    ];

    /**
     * @return list<string>
     */
    public static function cacheKeys(): array
    {
        return array_map(
            static fn (string $range): string => 'member_analytics.' . $range,
            array_keys(MemberAnalyticsQueryRequest::PRESETS)
        );
    }

    /**
     * Forget every preset window once a member or subscription row moves.
     *
     * Deferred to afterCommit for the reason `UserObserver` documents: Eloquent
     * events fire inside the transaction, so an immediate forget lets the next
     * reader repopulate from rows the database has not promised yet, and a
     * rollback cannot take that value back.
     *
     * shortcut: a custom range keeps its own key and is not enumerated here — it
     * goes stale for at most TTL. Upgrade if bookmarked custom windows start
     * being complained about.
     */
    public static function bustCache(): void
    {
        DB::afterCommit(function (): void {
            foreach (static::cacheKeys() as $key) {
                Cache::forget($key);
            }
        });
    }

    /**
     * @param  array{range: string, from: Carbon, to: Carbon}  $window
     * @return array<string, mixed>
     */
    public function run(array $window): array
    {
        $key = $window['range'] === 'custom'
            ? 'member_analytics.custom.' . $window['from']->format('Y-m-d') . '.' . $window['to']->format('Y-m-d')
            : 'member_analytics.' . $window['range'];

        return Cache::remember($key, self::TTL, fn (): array => $this->build($window));
    }

    /**
     * @param  array{range: string, from: Carbon, to: Carbon}  $window
     * @return array<string, mixed>
     */
    private function build(array $window): array
    {
        $from = $window['from'];
        $to = $window['to'];
        $buckets = $this->buckets($from, $to);

        $growth = $this->histogram(Member::query()->toBase(), 'joined_at', $buckets);
        $expirations = $this->histogram(
            MemberSubscription::query()->whereIn('status', [
                MemberSubscription::STATUS_ACTIVE,
                MemberSubscription::STATUS_EXPIRED,
            ])->toBase(),
            'expires_at',
            $buckets
        );

        // Bar widths are relative to the busiest bucket, so the tallest bar fills
        // the track. A `1` keeps an empty window from dividing by zero.
        $peak = max([...$growth, ...$expirations, 1]);
        $series = [];
        foreach ($buckets as $i => $bucket) {
            $series[] = [
                'label' => $bucket['label'],
                'new' => $growth[$i],
                'expired' => $expirations[$i],
                'new_pct' => (int) round($growth[$i] / $peak * 100),
                'expired_pct' => (int) round($expirations[$i] / $peak * 100),
            ];
        }

        $ended = array_sum($expirations);
        $renewed = $this->renewedCount($from, $to);
        [$dau, $mau] = $this->activityCounts($to);
        [$atRiskCount, $atRisk] = $this->atRisk($to);

        return [
            'filterUrl' => route('membership.dashboard'),
            'timeframes' => $this->timeframes($window),
            'range' => $window['range'],
            'window' => [
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
            ],
            'metrics' => [
                [
                    'label' => 'Renewal Rate',
                    'value' => $ended > 0 ? min(100, (int) round($renewed / $ended * 100)) . '%' : '—',
                    'hint' => 'Of the subscriptions that ended in this window, how many were followed by a new '
                        .'period for the same member. A dash means nothing ended in it.',
                ],
                [
                    'label' => 'Daily Engagement',
                    'value' => $mau > 0 ? (int) round($dau / $mau * 100) . '%' : '—',
                    'hint' => 'Members who signed in today, as a share of the members who signed in during the '
                        .'last 30 days. It reads the last sign-in on the account, not the membership rows.',
                ],
                [
                    'label' => 'Inactive Members',
                    'value' => (string) $atRiskCount,
                    'hint' => 'Members whose subscription is still active but who have not signed in for '
                        .self::DORMANT_DAYS.' days. A member who never signed in counts here too.',
                ],
            ],
            'series' => $series,
            'tenure' => $this->tenure(),
            'at_risk' => $atRisk,
            'at_risk_count' => $atRiskCount,
            'at_risk_days' => self::DORMANT_DAYS,
        ];
    }

    /**
     * Bucket boundaries, oldest first, coarse enough that no window renders more
     * than ~13 bars: daily to two weeks, weekly to a quarter, monthly beyond.
     *
     * @return list<array{label: string, from: Carbon, to: Carbon}>
     */
    private function buckets(Carbon $from, Carbon $to): array
    {
        $days = max(1, (int) $from->diffInDays($to));
        $granularity = match (true) {
            $days <= 14 => 'day',
            $days <= 92 => 'week',
            default => 'month',
        };

        $buckets = [];
        $cursor = $from->copy();

        while ($cursor->lt($to) && count($buckets) < 400) {
            $end = match ($granularity) {
                'day' => $cursor->copy()->addDay(),
                'week' => $cursor->copy()->addWeek(),
                default => $cursor->copy()->addMonthNoOverflow(),
            };
            $buckets[] = [
                'label' => match ($granularity) {
                    'day' => $cursor->format('d M'),
                    'week' => 'w/c ' . $cursor->format('d M'),
                    default => $cursor->format('M Y'),
                },
                'from' => $cursor->copy(),
                'to' => $end,
            ];
            $cursor = $end;
        }

        return $buckets;
    }

    /**
     * N `SUM(CASE …)` columns in one statement. Ranges are half-open — a row
     * landing on a boundary is counted once, never in two buckets. A missing
     * bound means the band is open-ended.
     *
     * @param  list<array{from: ?Carbon, to: ?Carbon}>  $ranges
     * @return list<int>
     */
    private function histogram(QueryBuilder $query, string $column, array $ranges): array
    {
        $select = [];
        $bindings = [];

        foreach ($ranges as $i => $range) {
            $conditions = [];

            if ($range['from'] !== null) {
                $conditions[] = $column . ' >= ?';
                $bindings[] = $range['from']->toDateTimeString();
            }
            if ($range['to'] !== null) {
                $conditions[] = $column . ' < ?';
                $bindings[] = $range['to']->toDateTimeString();
            }

            $select[] = 'SUM(CASE WHEN ' . implode(' AND ', $conditions) . ' THEN 1 ELSE 0 END) AS b' . $i;
        }

        // The base query, not the Eloquent one: an aggregate row is not a model,
        // and hydrating one into MemberSubscription only to read `b0…bN` back out
        // of `getAttributes()` is a detour through the wrong class. `Expression`
        // rather than `selectRaw` because the column list is assembled, so it is
        // not a literal string.
        $row = (array) $query
            ->addSelect(new Expression(implode(', ', $select)))
            ->addBinding($bindings, 'select')
            ->first();

        return array_map(static fn ($value): int => (int) $value, array_values($row));
    }

    /**
     * Tenure distribution from `members.joined_at`, relative to now.
     *
     * @return list<array{label: string, count: int, pct: int}>
     */
    private function tenure(): array
    {
        $now = Carbon::now();
        $upper = null;
        $ranges = [];

        foreach (self::TENURE_BANDS as $band) {
            $lower = $band['months'] === null ? null : $now->copy()->subMonths((int) $band['months']);
            $ranges[] = ['from' => $lower, 'to' => $upper];
            $upper = $lower;
        }

        $counts = $this->histogram(Member::query()->toBase(), 'joined_at', $ranges);
        $total = max(1, array_sum($counts));

        return array_map(
            static fn (int $i): array => [
                'label' => self::TENURE_BANDS[$i]['label'],
                'count' => $counts[$i],
                'pct' => (int) round($counts[$i] / $total * 100),
            ],
            array_keys($ranges)
        );
    }

    /**
     * Periods started inside the window that follow an already-expired period of
     * the same member — a renewal, not a first purchase.
     */
    private function renewedCount(Carbon $from, Carbon $to): int
    {
        return MemberSubscription::query()
            ->whereBetween('starts_at', [$from, $to])
            ->whereExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('member_subscriptions as prior')
                    ->whereColumn('prior.member_id', 'member_subscriptions.member_id')
                    ->where('prior.status', MemberSubscription::STATUS_EXPIRED)
                    ->whereColumn('prior.expires_at', '<=', 'member_subscriptions.starts_at');
            })
            ->count();
    }

    /**
     * Sign-ins per day and per month, both read from `users.last_activity_at`,
     * which `AuthLoginCompletedAction` writes at login. A snapshot, not a
     * historical series: no daily rollup table exists (D-13).
     *
     * @return array{0: int, 1: int}
     */
    private function activityCounts(Carbon $now): array
    {
        $since = fn (Carbon $cutoff): int => Member::query()
            ->whereHas('user', static fn ($q) => $q->where('last_activity_at', '>=', $cutoff))
            ->count();

        return [$since($now->copy()->subDay()), $since($now->copy()->subDays(30))];
    }

    /**
     * Live subscription and no sign-in for DORMANT_DAYS. A null
     * `last_activity_at` is an account that never signed in — the most dormant
     * state there is, so it belongs on the list.
     *
     * @return array{0: int, 1: list<array<string, string>>}
     */
    private function atRisk(Carbon $now): array
    {
        $cutoff = $now->copy()->subDays(self::DORMANT_DAYS);

        $query = MemberSubscription::currentlyActive()->whereHas(
            'member.user',
            static fn ($q) => $q->where(
                static fn ($n) => $n->where('users.last_activity_at', '<', $cutoff)->orWhereNull('users.last_activity_at')
            )
        );

        $count = (clone $query)->count();

        $rows = [];
        foreach ($query->with('member.user')->orderBy('expires_at')->limit(self::AT_RISK_ROWS)->get() as $subscription) {
            // `User` carries no @property docblock, so phpstan sees the cast
            // column as a string; Carbon::parse accepts either shape.
            $lastActivity = $subscription->member?->user?->last_activity_at;

            $rows[] = [
                'member_number' => (string) $subscription->member?->member_number,
                'user_name' => (string) $subscription->member?->user?->name,
                'expires_at' => $subscription->expires_at->format('Y-m-d'),
                'last_activity' => $lastActivity === null ? 'Never' : Carbon::parse($lastActivity)->format('Y-m-d'),
            ];
        }

        return [$count, $rows];
    }

    /**
     * Filter links, built here because Blade may not call `route()` (D-4).
     *
     * @param  array{range: string, from: Carbon, to: Carbon}  $window
     * @return list<array{label: string, url: string, active: bool}>
     */
    private function timeframes(array $window): array
    {
        $links = array_map(
            static fn (string $range): array => [
                'label' => strtoupper($range),
                'url' => route('membership.dashboard', ['range' => $range]),
                'active' => $window['range'] === $range,
            ],
            array_keys(MemberAnalyticsQueryRequest::PRESETS)
        );

        $links[] = [
            'label' => 'Custom',
            'url' => route('membership.dashboard', $window['range'] === 'custom'
                ? ['range' => 'custom', 'date_from' => $window['from']->format('Y-m-d'), 'date_to' => $window['to']->format('Y-m-d')]
                : ['range' => 'custom']),
            'active' => $window['range'] === 'custom',
        ];

        return $links;
    }
}
