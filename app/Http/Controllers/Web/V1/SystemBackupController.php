<?php

namespace App\Http\Controllers\Web\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ExecuteSystemBackupJob;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Queue a system backup from the management surface.
 *
 * There is nothing to validate: the route gates say who may ask, and the
 * settings the job reads are already stored. The response is a redirect, not a
 * job result — the backup runs on the `backups` queue and notifies its
 * audience when it finishes or refuses.
 */
class SystemBackupController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        ExecuteSystemBackupJob::dispatch($request->user()?->getKey())->afterCommit();

        return back()->with('status', 'Backup queued.');
    }
}
