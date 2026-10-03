<?php

namespace App\Actions\V1\User;

use App\Enums\UserStatusEnum;
use App\Models\RoleLookup;
use App\Models\User;
use App\Support\SystemRole;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * User index listing with search, filter, sort, and counts.
 */
class UserIndexAction
{
    /**
     * How long a resolved count stays readable before it is recomputed anyway.
     *
     * A safety net, not the freshness policy — every mutation busts the key via
     * `bustCache()`, so an operator sees the new number immediately. This only
     * bounds how long a MISSED invalidation can persist: without it the count
     * is `rememberForever`, so one forgotten event would report a wrong number
     * until an unrelated user save happened to bust the key by luck.
     *
     * ponytail: one day is long enough that a busy day never pays to recompute,
     * and short enough that a missed bust could not have shipped unnoticed. The
     * real fix is recomputing per request — the count is one conditional
     * aggregate — not a smaller number here.
     */
    private const TTL = 86400;

    /**
     * Get cached counts by user status.
     *
     * @return array{active: int, inactive: int, locked: int, trashed: int}
     */
    public static function cacheKeys(): array
    {
        return ['user_index_counts.all', 'user_index_counts.masked'];
    }

    /**
     * Forget both count keys once the write that changed them is durable.
     *
     * One helper rather than a literal in every caller, for the reason
     * `FeatureIndexAction::SNAPSHOT_KEY` is public: four private constants in
     * four files are four unrelated strings, and renaming this left the
     * invalidators flushing keys nothing read — with the deploy green.
     *
     * Deferred to `DB::afterCommit` for the reason `UserObserver` documents:
     * Eloquent events fire inside the transaction that caused them, so an
     * immediate forget lets the next reader repopulate from rows the database
     * has not promised yet, and a rollback cannot take that value back.
     */
    public static function bustCache(): void
    {
        DB::afterCommit(function (): void {
            foreach (static::cacheKeys() as $key) {
                Cache::forget($key);
            }
        });
    }

    public function counts(?User $viewer = null): array
    {
        $seesSuperadmin = RoleLookup::viewerIsSuperAdmin($viewer);

        // Two keys, not one: the totals must equal the rows the viewer actually
        // sees, and the hidden superadmin account must not inflate them. A single
        // shared key would either leak it or under-report it for the superadmin.
        return Cache::remember(
            'user_index_counts.' . ($seesSuperadmin ? 'all' : 'masked'),
            static::TTL,
            function () use ($seesSuperadmin): array {
                $query = DB::table('users');

                if (! $seesSuperadmin) {
                    $query->whereNotIn('id', function ($sub): void {
                        $sub->select('model_id')
                            ->from('model_has_roles')
                            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                            ->where('roles.name', SystemRole::SUPERADMIN);
                    });
                }

                $row = $query
                    ->selectRaw('
                        COUNT(CASE WHEN is_active = true  AND is_locked = false AND deleted_at IS NULL THEN 1 END) as active,
                        COUNT(CASE WHEN is_active = false AND is_locked = false AND deleted_at IS NULL THEN 1 END) as inactive,
                        COUNT(CASE WHEN is_locked = true  AND deleted_at IS NULL        THEN 1 END) as locked,
                        COUNT(CASE WHEN deleted_at IS NOT NULL                              THEN 1 END) as trashed
                    ')
                    ->first();

                return [
                    'active'   => (int) $row->active,
                    'inactive' => (int) $row->inactive,
                    'locked'   => (int) $row->locked,
                    'trashed'  => (int) $row->trashed,
                ];
            }
        );
    }

    /**
     * Get a paginated list of users with optional search, status filter, and sort.
     *
     * @param  string|null       $search
     * @param  string|null       $status
     * @param  string            $sort
     * @param  string            $direction
     * @param  int               $perPage
     * @return LengthAwarePaginator
     */
    public function run(
        ?string $search = null,
        ?string $status = 'active',
        ?string $sort = 'created_at',
        ?string $direction = 'desc',
        int $perPage = 10,
        ?User $viewer = null,
    ): LengthAwarePaginator {
        $query = User::withTrashed();

        // The superadmin account is not a normal user: it is hidden from anyone
        // who is not a superadmin, so it neither appears in the list nor counts
        // in the totals below.
        if (! RoleLookup::viewerIsSuperAdmin($viewer)) {
            $query->whereDoesntHave('roles', fn ($q) => $q->where('name', SystemRole::SUPERADMIN));
        }

        $this->applySearch($query, $search);
        $this->applyStatusFilter($query, $status);
        $this->applySort($query, $sort, $direction);

        // Trashed users always at bottom
        $query->orderByRaw('deleted_at IS NOT NULL ASC');

        return $query->paginate($perPage)->withQueryString();
    }

    /**
     * Apply a name/email search filter.
     *
     * @param  Builder       $query
     * @param  string|null   $search
     */
    protected function applySearch(Builder $query, ?string $search): void
    {
        if (! $search) {
            return;
        }

        $query->where(function (Builder $q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
              ->orWhere('email', 'like', "%{$search}%");
        });
    }

    /**
     * Apply a status filter to the query.
     *
     * @param  Builder       $query
     * @param  string|null   $status
     */
    protected function applyStatusFilter(Builder $query, ?string $status): void
    {
        if (! $status) {
            return;
        }

        match ($status) {
            UserStatusEnum::ACTIVE->value       => $query->where('is_active', true)->where('is_locked', false)->whereNull('deleted_at'),
            UserStatusEnum::INACTIVE->value     => $query->where('is_active', false)->where('is_locked', false)->whereNull('deleted_at'),
            UserStatusEnum::LOCKED->value       => $query->where('is_locked', true)->whereNull('deleted_at'),
            UserStatusEnum::PENDING_VERIFICATION->value => $query->whereNull('email_verified_at')->whereNull('deleted_at'),
            UserStatusEnum::TRASHED->value      => $query->whereNotNull('deleted_at'),
            default => null,
        };
    }

    /**
     * Apply sort order to the query.
     *
     * @param  Builder  $query
     * @param  string   $sort
     * @param  string   $direction
     */
    protected function applySort(Builder $query, string $sort, string $direction): void
    {
        $allowed = ['created_at', 'name', 'email', 'is_active'];

        if (! in_array($sort, $allowed, true)) {
            $sort = 'created_at';
        }

        $query->orderBy($sort, $direction === 'asc' ? 'asc' : 'desc');
    }
}
