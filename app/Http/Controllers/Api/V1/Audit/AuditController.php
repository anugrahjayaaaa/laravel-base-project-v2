<?php

namespace App\Http\Controllers\Api\V1\Audit;

use App\Actions\V1\Audit\AuditExportDownloadAction;
use App\Actions\V1\Audit\AuditExportRequestAction;
use App\Actions\V1\Audit\AuditIndexAction;
use App\Actions\V1\Audit\AuditShowAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Audit\ActivityQueryRequest;
use App\Http\Requests\V1\Audit\AuditExportRequest;
use App\Http\Resources\Api\V1\Audit\ActivityResource;
use App\Http\Resources\Api\V1\Audit\AuditExportResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The audit trail over the API (Phase 10 follow-up, web/API gap audit).
 *
 * ## Why this existed at all
 *
 * Every WRITE to the audit trail already went through the Actions — both
 * channels share them, which is the rule this project follows everywhere else.
 * What was missing was the READ side on one channel: `GET /activity-logs` and
 * the export surface were browser-only, so a client that is not a browser could
 * not see what happened or take an extract. For a base project whose premise is
 * that the UI is replaceable, a module whose reading side only exists in Blade
 * is a module the UI owns.
 *
 * ## No logic in this class
 *
 * The same Actions, the same Form Requests, the same Resources' decision about
 * what to expose. `ActivityQueryRequest` and `AuditExportRequest` are shared
 * with the web controller verbatim, so a filter this endpoint accepts is a
 * filter the viewer accepts — an export is "the list you are looking at", on
 * both channels, because there is one definition of that list.
 *
 * ## A read writes nothing, and that still holds here
 *
 * Same rule as the web viewer: the row recording "an administrator read the
 * audit trail" would be a row in the trail being read.
 */
class AuditController extends Controller
{
    public function __construct(
        private readonly AuditIndexAction $indexAction,
        private readonly AuditShowAction $showAction,
        private readonly AuditExportRequestAction $exportRequestAction,
    ) {
    }

    /**
     * List audit rows, filtered and paginated.
     *
     * @param  ActivityQueryRequest  $request
     */
    public function index(ActivityQueryRequest $request): JsonResponse
    {
        $activities = $this->indexAction->run($request->filters());

        return $this->respond('', 200, [
            'activities' => ActivityResource::collection($activities),
            'pagination' => [
                'current_page' => $activities->currentPage(),
                'last_page' => $activities->lastPage(),
                'per_page' => $activities->perPage(),
                'total' => $activities->total(),
            ],
        ]);
    }

    /**
     * One audit row.
     *
     * `AuditShowAction::run()` throws `ModelNotFoundException`, which Laravel
     * renders as 404 — the right answer for an id that was never there and for
     * one that has since been pruned, and the same answer either way.
     */
    public function show(AuditShowAction $showAction, string $activity): JsonResponse
    {
        return $this->respond('', 200, [
            'activity' => new ActivityResource($showAction->run($activity)),
        ]);
    }

    /**
     * Queue an export of the current filters.
     *
     * 202, not 200: the file does not exist yet and asking for it is not the
     * same request as having it. The web controller redirects and flashes the
     * same message for the same reason — a 200 with a body nobody renders reads
     * as a success the user has to take on trust.
     *
     * The row is returned, not a bare acknowledgement, because the client needs
     * the id to poll and the download URL to come back to. Which filters produced
     * it come back in the same payload, so a client that requested five exports
     * can tell them apart without keeping its own state.
     *
     * @param  AuditExportRequest  $request
     */
    public function storeExport(AuditExportRequest $request): JsonResponse
    {
        $filters = $request->exportFilters();

        $export = $this->exportRequestAction->run(
            $request->user(),
            $filters,
            $filters['sort'] ?? 'created_at',
            $filters['direction'] ?? 'desc',
        );

        // `refresh()` because the row was CREATED, not read: `status` has a
        // database default of `pending` that no attribute of the freshly-built
        // model carries, so the instance the action hands back has `status ===
        // null` until it is reloaded. The web controller never notices — its
        // `recentFor()` re-queries from the database — but a resource that reads
        // `$this->status->value` would 500 on it. One extra SELECT on a route
        // that has just done an INSERT and dispatched a job.
        return $this->respond('Your export is being prepared. We will notify you when it is ready to download.', 202, [
            'export' => new AuditExportResource($export->refresh()),
        ]);
    }

    /**
     * The viewer's own recent exports.
     *
     * Scoped inside `recentFor()`, so this is the same list the audit page shows
     * and not a wider one an API client could reach. A client polling for a
     * finished export needs exactly this: `status` moves to `ready` and
     * `download_url` appears, with no new endpoint and no push channel.
     */
    public function exports(Request $request, AuditExportRequestAction $exportAction): JsonResponse
    {
        return $this->respond('', 200, [
            'exports' => AuditExportResource::collection($exportAction->recentFor($request->user())),
        ]);
    }

    /**
     * Stream one export file.
     *
     * `AuditExportDownloadAction` owns the ownership check, the expiry answer
     * and the streamed response. Those are the interesting parts of this route
     * and none of them are API-specific: an id in a URL is attacker-supplied on
     * both channels, and the answer for someone else's file is 403 either way.
     */
    public function downloadExport(Request $request, AuditExportDownloadAction $downloadAction, string $export): StreamedResponse
    {
        return $downloadAction->run($request->user(), $export);
    }
}
