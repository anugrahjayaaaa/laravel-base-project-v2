<?php

namespace App\Actions\V1\Member;

use App\Models\Member;
use App\Models\MemberPointHistory;
use App\Models\SystemSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The annual points reset behind `member_points_expiry_date` (Phase 18, O-3).
 *
 * Owner decision 2026-11-05: points expire on one configured date and then once
 * a year after it, and the setting is optional — empty means they never expire.
 * What is zeroed is the balance, and every zero leaves a negative ledger row
 * behind: a silent `UPDATE members SET points_balance = 0` would empty a balance
 * the trail cannot explain.
 *
 * The date is the next expiry, not a fixed anniversary. After a run it moves one
 * year forward, which is what makes the reset annual rather than daily.
 */
class MemberExpirePointsAction
{
    public const REASON = 'Annual points expiry';

    /**
     * Reset every positive balance once the configured date has passed.
     *
     * @return int How many members were reset — 0 on a disabled or not-yet-due run
     */
    public function run(): int
    {
        $expiry = $this->dueDate();

        if ($expiry === null) {
            return 0;
        }

        $count = 0;

        // chunkById, not chunk: the loop writes `points_balance`, the column
        // this query filters on, and an offset page would skip the members
        // that shifted out of the window. Ids do not move, so this one is
        // stable. Soft-deleted profiles are outside the default scope.
        Member::where('points_balance', '>', 0)->chunkById(500, function ($members) use (&$count): void {
            // One transaction per chunk, not one per run: a single 100k-member
            // reset would hold every row lock for minutes and grow the
            // rollback segment with it. A chunk that dies is picked up by the
            // next tick, and a profile already at zero is skipped, so nothing
            // is applied twice. What it does widen: points credited while the
            // run is still walking land inside the reset — the balance is
            // re-read under the lock, so the ledger row is still exact.
            DB::transaction(function () use ($members, &$count): void {
                foreach ($members as $member) {
                    // The balance is re-read under the row lock, the same way
                    // `MemberAdjustPointsAction` reads it: the value the chunk
                    // selected can be a page old by the time this loop reaches
                    // it, and zeroing a stale copy both throws away points that
                    // were just earned and stores a `balance_after` the ledger
                    // cannot support.
                    $locked = Member::whereKey($member->getKey())->lockForUpdate()->firstOrFail();
                    $balance = $locked->points_balance;

                    if ($balance <= 0) {
                        continue;
                    }

                    $locked->update(['points_balance' => 0]);

                    MemberPointHistory::create([
                        'member_id' => $locked->getKey(),
                        'delta' => -$balance,
                        'balance_after' => 0,
                        'reason' => self::REASON,
                    ]);

                    $locked->audit('member.points_expired', null, [
                        'causer' => 'SYSTEM',
                        'member_number' => $locked->member_number,
                        'points_removed' => $balance,
                    ]);

                    $count++;
                }
            });
        });

        // Written after the chunks commit, not around them. A run that reset
        // rows and died before this line re-runs on the next tick against
        // balances that are already zero, which lands nowhere; the date can
        // only move forward while nothing is left to reset.
        SystemSetting::set('member_points_expiry_date', $this->nextYearAfter($expiry)->toDateString());

        return $count;
    }

    /**
     * The configured date, but only once today has reached it.
     *
     * An unparseable row is treated as "not configured" rather than a crash:
     * the validation rule is the guard, this is the belt for a row written by
     * hand or by an old dump.
     */
    private function dueDate(): ?Carbon
    {
        $date = SystemSetting::getString('member_points_expiry_date', '');

        if ($date === '' || ! Carbon::hasFormat($date, 'Y-m-d')) {
            return null;
        }

        $expiry = Carbon::parse($date)->startOfDay();

        return $expiry->lessThanOrEqualTo(now()->startOfDay()) ? $expiry : null;
    }

    /**
     * The first anniversary strictly after today.
     *
     * An operator can deliberately store a past date to force a reset now.
     * Adding one year to `2020-01-01` gives `2021-01-01` — still past — so the
     * next tick would reset again. Stepping by whole years until the date is in
     * the future makes a forced reset a one-time event.
     */
    private function nextYearAfter(Carbon $expiry): Carbon
    {
        $next = $expiry->copy();
        $today = now()->startOfDay();

        while ($next->lessThanOrEqualTo($today)) {
            $next->addYear();
        }

        return $next;
    }
}
