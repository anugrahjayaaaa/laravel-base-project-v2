<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;

trait Auditable
{
    public function audit(string $event, ?Model $causer = null, array $properties = []): void
    {
        $source = request()->is('api/*') ? 'api' : 'web';

        activity()
            ->on($this)
            ->event($event)
            ->causedBy($causer)
            ->withProperties(array_merge(['source' => $source], $properties))
            ->log($event);
    }
}