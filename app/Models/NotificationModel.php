<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['type', 'notifiable_id', 'notifiable_type', 'data', 'read_at'])]
class NotificationModel extends Model
{
    use HasFactory, Notifiable, HasUlids;

    protected $casts = [
        'data' => 'array',
        'read_at' => 'datetime',
    ];

    public function notifiable()
    {
        return $this->morphTo();
    }

    public function queueableChain(): array
    {
        return [];
    }
}
