<?php

namespace App\Actions\V1\User;

use App\Enums\UserStatusEnum;
use App\Models\User;
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
     * Get cached counts by user status.
     *
     * @return array{active: int, inactive: int, locked: int, trashed: int}
     */
    public function counts(): array
    {
        return Cache::rememberForever('user_index_counts', function () {
            $row = DB::table('users')
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
        });
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
    ): LengthAwarePaginator {
        $query = User::withTrashed();

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
