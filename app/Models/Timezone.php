<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'label', 'utc_offset', 'is_active'])]
/**
 * Reference timezone options for the system settings UI.
 */
class Timezone extends Model
{
    /**
     * Return only selectable timezone options.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
