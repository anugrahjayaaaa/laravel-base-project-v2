<?php

namespace App\Actions\V1\Member;

use App\Models\Member;
use App\Models\MemberPointHistory;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * One signed points movement on a member's balance (Phase 18, P18-B4).
 *
 * The ledger row and the balance are written in one transaction, under a lock on
 * the member row: two adjustments posted at the same moment would otherwise both
 * read the old balance and lose one. `balance_after` is stored, not derived, so
 * the trail reads without recomputation. A correction is another row — nothing
 * here edits a ledger entry.
 */
class MemberAdjustPointsAction
{
    /**
     * @param  array<string, mixed>  $data  `delta` or `purchase_amount`, plus `reason`
     */
    public function run(Member $member, array $data, ?User $causer = null): void
    {
        $delta = isset($data['purchase_amount'])
            ? $this->fromPurchase((int) $data['purchase_amount'])
            : (int) $data['delta'];

        DB::transaction(function () use ($member, $delta, $data, $causer): void {
            $locked = Member::whereKey($member->getKey())->lockForUpdate()->firstOrFail();
            $before = $locked->points_balance;
            $after = $before + $delta;

            if ($after < 0) {
                throw ValidationException::withMessages([
                    'delta' => 'The balance is '.$before.'; this takes '.abs($delta).'.',
                ]);
            }

            $locked->update(['points_balance' => $after]);

            MemberPointHistory::create([
                'member_id' => $locked->getKey(),
                'delta' => $delta,
                'balance_after' => $after,
                'reason' => $data['reason'],
                'actor_id' => $causer?->getKey(),
            ]);

            $locked->audit('member.points_adjusted', $causer, [
                'member_number' => $locked->member_number,
                'delta' => $delta,
                'balance_before' => $before,
                'balance_after' => $after,
                'reason' => $data['reason'],
            ]);
        });
    }

    /**
     * Every whole multiple of `member_points_earn_amount` earns
     * `member_points_earn_value` (Rp 50.000 -> 1.000 at the shipped defaults).
     * A step below 1 is earning switched off, so the amount is refused instead
     * of crediting nothing; a partial step is not rounded up.
     *
     * shortcut: `member_tiers.point_multiplier` is not applied — the owner asked
     * for one global rule, and a per-tier factor would be a second one.
     */
    private function fromPurchase(int $amount): int
    {
        $step = SystemSetting::getInt('member_points_earn_amount', 50000);
        $perStep = SystemSetting::getInt('member_points_earn_value', 1000);

        if ($step < 1 || $perStep < 1) {
            throw ValidationException::withMessages([
                'purchase_amount' => 'Points earning is switched off in system settings.',
            ]);
        }

        $points = intdiv($amount, $step) * $perStep;

        if ($points === 0) {
            throw ValidationException::withMessages([
                'purchase_amount' => 'The amount does not reach the earning step of '.$step.' rupiah.',
            ]);
        }

        return $points;
    }
}
