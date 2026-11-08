<?php

namespace App\Actions\V1\Member;

use App\Actions\V1\BulkAction\BulkActionHandler;
use App\Models\MemberTier;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Bulk action handler for membership tiers (Phase 18).
 *
 * Same contract as `MemberBulkActionHandler`: `getValidItems()` is the boundary,
 * so a live tier cannot be restored and a trashed one cannot be trashed again,
 * and force-delete requires the row to be in the trash first.
 *
 * `MemberTierDeleteAction`'s in-use refusal still applies per row here. Selecting
 * a tier that carries subscriptions aborts the whole batch with a validation
 * error rather than skipping it: a bulk bar that silently dropped the rows it
 * could not delete would tell the operator the rest of the selection worked.
 */
class MemberTierBulkActionHandler implements BulkActionHandler
{
    /**
     * @param  array<int>  $ids
     * @return Collection<int, Model>
     */
    public function getValidItems(array $ids, string $action): Collection
    {
        /** @var Collection<int, Model> $valid */
        $valid = MemberTier::withTrashed()->whereIn('id', $ids)->get()->filter(
            fn (MemberTier $tier): bool => match ($action) {
                'delete' => ! $tier->trashed(),
                'restore', 'force_delete' => $tier->trashed(),
                default => false,
            }
        );

        return $valid;
    }

    /**
     * @param  array<int>  $ids
     */
    public function executeBulk(string $action, array $ids): void
    {
        $causer = auth()->user();
        $tiers = MemberTier::withTrashed()->whereIn('id', $ids)->get();

        // An action outside the three below is a bug, not a no-op — see
        // `MemberBulkActionHandler::executeBulk()`.
        $tiers->each(match ($action) {
            'delete' => fn (MemberTier $tier) => app(MemberTierDeleteAction::class)->run($tier, $causer),
            'restore' => fn (MemberTier $tier) => app(MemberTierRestoreAction::class)->run($tier, $causer),
            'force_delete' => fn (MemberTier $tier) => app(MemberTierForceDeleteAction::class)->run($tier, $causer),
            default => throw new InvalidArgumentException("Tier bulk action [{$action}] is not implemented."),
        });
    }

    /**
     * Empty: each branch loops an Action that writes its own `member_tier.*` row.
     */
    public function getAuditEvent(string $action): string
    {
        return '';
    }

    public function getAuditLabel(string $action): string
    {
        return match ($action) {
            'delete' => 'tiers moved to trash',
            'restore' => 'tiers restored',
            'force_delete' => 'tiers permanently deleted',
            default => 'Action completed',
        };
    }

    /**
     * No cache keys: `MemberTierIndexAction` reads the table per request.
     *
     * @return array<int, string>
     */
    public function getCacheKeys(): array
    {
        return [];
    }

    /**
     * @param  array<int>  $ids
     */
    public function batchInvalidateSessions(array $ids): void
    {
        // A tier is configuration — no account's session depends on it.
    }

    /** @return array<int, string> */
    public function getSessionInvalidationActions(): array
    {
        return [];
    }
}
