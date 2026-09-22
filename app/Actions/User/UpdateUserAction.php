<?php

namespace App\Actions\User;

use App\Models\SystemSetting;
use App\Models\User;
use App\Notifications\ChangeEmailVerificationNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UpdateUserAction
{
    public function run(User $user, array $data): User
    {
        $user->update([
            'name' => $data['name'] ?? $user->name,
            'is_active' => isset($data['status'])
                ? $data['status'] === \App\Enums\UserStatusEnum::ACTIVE->value
                : $user->is_active,
        ]);

        if (isset($data['username']) && $data['username'] !== $user->username) {
            if (method_exists($user, 'canChangeUsername') && ! $user->canChangeUsername()) {
                $cooldown = (int) (SystemSetting::where('key', 'username_change_cooldown_days')->value('value') ?? 30);
                $nextDate = $user->username_changed_at?->copy()->addDays($cooldown)->format('Y-m-d');
                throw ValidationException::withMessages(['username' => "Username changes are currently disabled. Next change allowed on {$nextDate}."]);
            }

            $user->update([
                'username' => $data['username'],
                'username_changed_at' => now(),
            ]);
        }

        if (isset($data['email']) && $data['email'] !== $user->email) {
            if (class_exists(\App\Notifications\ChangeEmailVerificationNotification::class)) {
                if (method_exists($user, 'canChangeEmail') && ! $user->canChangeEmail()) {
                    $cooldown = (int) (SystemSetting::where('key', 'email_change_cooldown_days')->value('value') ?? 30);
                    $nextDate = $user->email_changed_at?->copy()->addDays($cooldown)->format('Y-m-d');
                    throw ValidationException::withMessages(['email' => "Email changes are currently disabled. Next change allowed on {$nextDate}."]);
                }

                $token = Str::random(64);

                $user->update([
                    'pending_email' => $data['email'],
                    'email_change_token' => $token,
                    'email_change_token_expires_at' => now()->addHours(24),
                ]);
                
                Notification::send($user->fresh(), new ChangeEmailVerificationNotification($data['email'], $token));
            } else {
                $user->update(['email' => $data['email']]);
            }
        }

        if (isset($data['password']) && $data['password']) {
            $user->update(['password' => Hash::make($data['password'])]);
        }

        return $user->fresh();
    }
}
