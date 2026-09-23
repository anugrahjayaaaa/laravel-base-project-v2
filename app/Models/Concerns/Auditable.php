<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Auditable trait: logs model events via spatie/laravel-activitylog.
 */
trait Auditable
{
    /**
     * Log an audit event on the model.
     *
     * @param string $event Event name
     * @param Model|null $causer User who triggered the event
     * @param array $properties Extra properties to log
     * @return void
     */
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