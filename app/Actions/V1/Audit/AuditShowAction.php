<?php

namespace App\Actions\V1\Audit;

use App\Models\Activity;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Fetch one audit row for the detail page.
 *
 * An Action rather than an inline `Activity::find()` so the controller stays a
 * formatter and the "not found" answer is one place. `Activity` carries
 * `$guarded = ['*']` — it is a read model — so there is no second reason to
 * reach for a query outside here.
 *
 * The relations are eager-loaded even though this is a single row: `causer` and
 * `subject` are two separate queries, and the detail page renders both. The
 * saving is two round trips, not a scaling factor, which is why the index
 * action's eager load is the one that actually matters.
 */
class AuditShowAction
{
    /**
     * @throws ModelNotFoundException when the row does not exist
     */
    public function run(int|string $activity): Activity
    {
        return Activity::query()
            ->with(['causer', 'subject'])
            ->findOrFail($activity);
    }
}
