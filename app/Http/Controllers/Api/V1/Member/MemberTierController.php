<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Actions\V1\BulkAction\BulkActionProcessor;
use App\Actions\V1\Member\MemberTierActivateAction;
use App\Actions\V1\Member\MemberTierBulkActionHandler;
use App\Actions\V1\Member\MemberTierCreateAction;
use App\Actions\V1\Member\MemberTierDeactivateAction;
use App\Actions\V1\Member\MemberTierDeleteAction;
use App\Actions\V1\Member\MemberTierForceDeleteAction;
use App\Actions\V1\Member\MemberTierIndexAction;
use App\Actions\V1\Member\MemberTierRestoreAction;
use App\Actions\V1\Member\MemberTierUpdateAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Member\BulkMemberTierRequest;
use App\Http\Requests\V1\Member\MemberTierQueryRequest;
use App\Http\Requests\V1\Member\MemberTierRequest;
use App\Http\Resources\Api\V1\Member\MemberTierResource;
use App\Models\MemberTier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tier configuration over the API (Phase 18, P18-B6).
 *
 * The twin of `Web\V1\MemberTierController`: same Actions, same Form Requests,
 * same permissions. There is no read-by-id because the web detail screen is the
 * edit page — the list already returns every column a client can price with.
 */
class MemberTierController extends Controller
{
    public function __construct(
        private readonly MemberTierIndexAction $indexAction,
        private readonly MemberTierCreateAction $createAction,
        private readonly MemberTierUpdateAction $updateAction,
        private readonly MemberTierDeleteAction $deleteAction,
        private readonly MemberTierRestoreAction $restoreAction,
        private readonly MemberTierForceDeleteAction $forceDeleteAction,
        private readonly MemberTierActivateAction $activateAction,
        private readonly MemberTierDeactivateAction $deactivateAction,
        private readonly BulkActionProcessor $bulkProcessor,
        private readonly MemberTierBulkActionHandler $bulkHandler,
    ) {
    }

    /**
     * The tiers, in the order the pricing screen shows them.
     */
    public function index(MemberTierQueryRequest $request): JsonResponse
    {
        $tiers = $this->indexAction->query(
            search: $request->validated('search'),
            status: $request->validated('status') ?? 'active',
        );

        return $this->respond('', 200, [
            'tiers' => MemberTierResource::collection($tiers),
            'pagination' => $this->paginationFor($tiers),
        ]);
    }

    /**
     * Add a tier. The slug is derived and `superadmin` is refused by the same
     * rules `MemberTierRequest` applies to the browser form.
     */
    public function store(MemberTierRequest $request): JsonResponse
    {
        $tier = $this->createAction->run($request->validated(), $request->user());

        return $this->respond('Tier created successfully.', 201, [
            'tier' => new MemberTierResource($tier),
        ]);
    }

    /**
     * Change a tier. A rename reaches new subscriptions only: every period keeps
     * the role copied onto its own row at activation.
     */
    public function update(MemberTierRequest $request, MemberTier $tier): JsonResponse
    {
        $this->updateAction->run($tier, $request->validated(), $request->user());

        return $this->respond('Tier updated successfully.', 200, [
            'tier' => new MemberTierResource($tier->fresh()),
        ]);
    }

    /**
     * Move a tier to the trash.
     *
     * 422 while a subscription still points at it — the delete Action refuses,
     * so pricing history cannot be pulled out from under a live period.
     */
    public function destroy(Request $request, MemberTier $tier): JsonResponse
    {
        $this->deleteAction->run($tier, $request->user());

        return $this->respond('Tier moved to trash successfully.');
    }

    /**
     * Restore a trashed tier.
     *
     * Raw id, not the `{tier}` binding: that resolves the default scope and
     * would 404 the only rows this route exists for. `onlyTrashed()` is the
     * other half — a live tier cannot be restored.
     */
    public function restore(Request $request, int $tier): JsonResponse
    {
        $trashed = MemberTier::onlyTrashed()->findOrFail($tier);

        $this->restoreAction->run($trashed, $request->user());

        return $this->respond('Tier restored successfully.', 200, [
            'tier' => new MemberTierResource($trashed->fresh()),
        ]);
    }

    /**
     * Permanently delete a trashed tier.
     */
    public function forceDelete(Request $request, int $tier): JsonResponse
    {
        $trashed = MemberTier::onlyTrashed()->findOrFail($tier);

        $this->forceDeleteAction->run($trashed, $request->user());

        return $this->respond('Tier permanently deleted.');
    }

    /**
     * Put a tier back on the price list.
     */
    public function activate(Request $request, MemberTier $tier): JsonResponse
    {
        $this->activateAction->run($tier, $request->user());

        return $this->respond('Tier activated successfully.');
    }

    /**
     * Take a tier off the price list. Periods already sold on it keep running.
     */
    public function deactivate(Request $request, MemberTier $tier): JsonResponse
    {
        $this->deactivateAction->run($tier, $request->user());

        return $this->respond('Tier deactivated successfully.');
    }

    /**
     * Act on several tiers at once — the same processor, handler and permission
     * map the tiers bulk bar uses.
     */
    public function bulkAction(BulkMemberTierRequest $request): JsonResponse
    {
        $result = $this->bulkProcessor->run(
            action: $request->validated('action'),
            ids: $request->validated('tier_ids'),
            causer: $request->user() ?? abort(403),
            handler: $this->bulkHandler,
        );

        return $this->respond("{$result['label']} ({$result['count']} tiers).");
    }
}
