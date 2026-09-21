<?php

namespace App\Actions\User;

use App\Enums\UserStatusEnum;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class UserIndexAction
{
    public function run(
        ?string $search = null,
        ?string $status = null,
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

    protected function applyStatusFilter(Builder $query, ?string $status): void
    {
        if (! $status) {
            return;
        }

        match ($status) {
            UserStatusEnum::ACTIVE->value => $query->where('is_active', true)->where('is_locked', false),
            UserStatusEnum::INACTIVE->value => $query->where('is_active', false),
            UserStatusEnum::LOCKED->value => $query->where('is_locked', true),
            UserStatusEnum::PENDING_VERIFICATION->value => $query->whereNull('email_verified_at'),
            default => null,
        };
    }

    protected function applySort(Builder $query, string $sort, string $direction): void
    {
        $allowed = ['created_at', 'name', 'email', 'is_active'];

        if (! in_array($sort, $allowed, true)) {
            $sort = 'created_at';
        }

        $query->orderBy($sort, $direction === 'asc' ? 'asc' : 'desc');
    }
}