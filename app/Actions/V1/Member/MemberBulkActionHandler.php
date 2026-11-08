<?php

namespace App\Actions\V1\Member;

use App\Actions\V1\BulkAction\BulkActionHandler;
use App\Models\Member;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Bulk action handler for member profiles (Phase 18).
 *
 * Mirrors `RoleBulkActionHandler`, and like it, `getValidItems()` is the
 * boundary: the browser's dropdown is only a convenience, so a trashed profile
 * reaching `delete` and a live one reaching `restore` / `force_delete` are
 * filtered out here rather than trusted to a disabled checkbox. A live profile
 * cannot be force-deleted from the bulk bar either — the two-step (trash first)
 * is the same one the row button enforces, because one click should never be
 * able to destroy a points history.
 */
class MemberBulkActionHandler implements BulkActionHandler
{
    /**
     * @param  array<int>  $ids
     * @return Collection<int, Model>
     */
    public function getValidItems(array $ids, string $action): Collection
    {
        /** @var Collection<int, Model> $valid */
        $valid = Member::withTrashed()->whereIn('id', $ids)->get()->filter(
            fn (Member $member): bool => match ($action) {
                'delete' => ! $member->trashed(),
                'restore', 'force_delete' => $member->trashed(),
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
        $members = Member::withTrashed()->whereIn('id', $ids)->get();

        // Each row goes through its own Action, so each writes its own audit row
        // inside its own transaction and nothing is updated around the guards.
        // An action outside the three below is a bug, not a no-op: returning a
        // closure that does nothing is how a caller used to get HTTP 200 with
        // zero rows acted on, so the arm throws instead (`UserBulkActionHandler`
        // gets the same guarantee by having no default arm at all).
        $members->each(match ($action) {
            'delete' => fn (Member $member) => app(MemberDeleteAction::class)->run($member, $causer),
            'restore' => fn (Member $member) => app(MemberRestoreAction::class)->run($member, $causer),
            'force_delete' => fn (Member $member) => app(MemberForceDeleteAction::class)->run($member, $causer),
            default => throw new InvalidArgumentException("Member bulk action [{$action}] is not implemented."),
        });
    }

    /**
     * Empty by design: every branch above loops an Action that writes its own
     * `member.*` row, so an aggregate row would name the same subjects twice.
     */
    public function getAuditEvent(string $action): string
    {
        return '';
    }

    public function getAuditLabel(string $action): string
    {
        return match ($action) {
            'delete' => 'members moved to trash',
            'restore' => 'members restored',
            'force_delete' => 'members permanently deleted',
            default => 'Action completed',
        };
    }

    /**
     * No cache keys: the members index counts are read per request
     * (`MemberIndexAction`'s `shortcut:` note), so there is nothing to forget.
     * Add the keys here the day those counts are cached.
     *
     * @return array<int, string>
     */
    public function getCacheKeys(): array
    {
        return [];
    }

    /**
     * No-op. Trashing a loyalty profile leaves the login account alone — that is
     * `UserDeleteAction`'s decision on its own screen — so nobody is logged out.
     *
     * @param  array<int>  $ids
     */
    public function batchInvalidateSessions(array $ids): void
    {
        //
    }

    /** @return array<int, string> */
    public function getSessionInvalidationActions(): array
    {
        return [];
    }
}
