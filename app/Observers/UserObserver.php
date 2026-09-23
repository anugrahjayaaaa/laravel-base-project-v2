<?php

namespace App\Observers;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

class UserObserver
{
    public function saved(User $user): void
    {
        Cache::forget('user_index_counts');
    }

    public function deleted(User $user): void
    {
        Cache::forget('user_index_counts');
    }

    public function restored(User $user): void
    {
        Cache::forget('user_index_counts');
    }
}
