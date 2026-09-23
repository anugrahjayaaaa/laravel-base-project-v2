<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Facades\Activity;

trait Auditable
{
    public function audit(string $event, ?Model $causer = null, array $properties = []): void
    {
        $activity = activity()
            ->on($this)
            ->event($event);

        if ($causer !== null) {
            $activity->causedBy($causer);
        }

        if (!empty($properties)) {
            $activity->withProperties($properties);
        }

        $activity->log($event);
    }
}