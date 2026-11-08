<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Actions\V1\BulkAction\BulkActionProcessor;
use App\Actions\V1\Member\MemberActivateAction;
use App\Actions\V1\Member\MemberAdjustPointsAction;
use App\Actions\V1\Member\MemberBulkActionHandler;
use App\Actions\V1\Member\MemberCreateAction;
use App\Actions\V1\Member\MemberDeactivateAction;
use App\Actions\V1\Member\MemberDeleteAction;
use App\Actions\V1\Member\MemberExpiryBroadcastAction;
use App\Actions\V1\Member\MemberForceDeleteAction;
use App\Actions\V1\Member\MemberIndexAction;
use App\Actions\V1\Member\MemberRestoreAction;
use App\Actions\V1\Member\MemberSubscriptionActivateAction;
use App\Actions\V1\Member\MemberSubscriptionCancelAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Member\BulkMemberRequest;
use App\Http\Requests\V1\Member\MemberCreateRequest;
use App\Http\Requests\V1\Member\MemberDeactivateRequest;
use App\Http\Requests\V1\Member\MemberAdjustPointsRequest;
use App\Http\Requests\V1\Member\MemberQueryRequest;
use App\Http\Requests\V1\Member\MemberSubscribeRequest;
use App\Http\Resources\Api\V1\Member\MemberPointHistoryResource;
use App\Http\Resources\Api\V1\Member\MemberResource;
use App\Http\Resources\Api\V1\Member\MemberSubscriptionResource;
use App\Models\Member;
use App\Models\MemberSubscription;
use App\Support\ApiError;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Member profiles over the API (Phase 18, P18-B6).
 *
 * The twin of `Web\V1\MemberController`, and it holds no logic of its own: the
 * same Actions, the same Form Requests and the same route permissions answer
 * both channels, so "may this caller issue a period" is decided once. The only
 * difference is the shape of the reply — JSON where the browser gets a
 * redirect.
 */
class MemberController extends Controller
{
    public function __construct(
        private readonly MemberIndexAction $indexAction,
        private readonly MemberCreateAction $createAction,
        private readonly MemberAdjustPointsAction $pointsAction,
        private readonly MemberSubscriptionActivateAction $subscriptionActivateAction,
        private readonly MemberSubscriptionCancelAction $subscriptionCancelAction,
        private readonly MemberActivateAction $activateAction,
        private readonly MemberDeactivateAction $deactivateAction,
        private readonly MemberDeleteAction $deleteAction,
        private readonly MemberRestoreAction $restoreAction,
        private readonly MemberForceDeleteAction $forceDeleteAction,
        private readonly MemberExpiryBroadcastAction $broadcastAction,
        private readonly BulkActionProcessor $bulkProcessor,
        private readonly MemberBulkActionHandler $bulkHandler,
    ) {
    }

    /**
     * The members list: same rows, same order, same page size as the table.
     */
    public function index(MemberQueryRequest $request): JsonResponse
    {
        $members = $this->indexAction->query(
            search: $request->validated('search'),
            sort: $request->validated('sort') ?? 'member_number',
            direction: $request->validated('direction') ?? 'asc',
            status: $request->validated('status') ?? 'active',
        );

        return $this->respond('', 200, [
            'members' => MemberResource::collection($members),
            'pagination' => $this->paginationFor($members),
        ]);
    }

    /**
     * One profile with its periods and points ledger, in three reads of the
     * relations the detail page already walks.
     */
    public function show(Member $member): JsonResponse
    {
        $member->load(Member::DETAIL_WITH);

        return $this->respond('', 200, [
            'member' => new MemberResource($member),
            'subscriptions' => MemberSubscriptionResource::collection($member->subscriptions),
            'points' => MemberPointHistoryResource::collection($member->pointHistories),
        ]);
    }

    /**
     * Register a member: the login account first, then the loyalty profile.
     *
     * 422 when the account exists or the payload is short; the role that makes
     * the profile a member is applied by the Action, which also means a caller
     * without `users.assign_roles` is refused here exactly as on the web route.
     */
    public function store(MemberCreateRequest $request): JsonResponse
    {
        $member = $this->createAction->run($request->validated(), $request->user());

        return $this->respond('Member created successfully.', 201, [
            'member' => new MemberResource($member),
        ]);
    }

    /**
     * Move the points balance by a signed delta or a purchase amount.
     *
     * 422 when both or neither of the two arrive, and when the deduction would
     * take the balance below zero — `MemberAdjustPointsAction` refuses the
     * write before it opens the ledger row.
     *
     * 404 for a profile in the trash: `MemberExpirePointsAction` scans the
     * default scope, so points credited to a trashed row would never expire.
     */
    #[Response(status: 404, type: ApiError::DOC_TYPE)]
    public function points(MemberAdjustPointsRequest $request, Member $member): JsonResponse
    {
        abort_if($member->trashed(), 404);

        $this->pointsAction->run($member, $request->validated(), $request->user());

        return $this->respond('Points adjusted successfully.', 200, [
            'points_balance' => $member->refresh()->points_balance,
        ]);
    }

    /**
     * Issue a membership period on a tier.
     *
     * 422 for a retired tier or a second live period: the refusal is the
     * `MemberSubscriptionActivateAction` check, not a filter on this route.
     */
    public function subscribe(MemberSubscribeRequest $request, Member $member): JsonResponse
    {
        abort_if($member->trashed(), 404);

        $subscription = $this->subscriptionActivateAction->run($member, $request->tier(), $request->user());

        return $this->respond('Subscription activated successfully.', 201, [
            'subscription' => new MemberSubscriptionResource($subscription->load('tier')),
        ]);
    }

    /**
     * End one period early, revoking only the role that period applied.
     *
     * 404 when the period's profile is in the trash, the same refusal points
     * and subscribe already make.
     */
    #[Response(status: 404, type: ApiError::DOC_TYPE)]
    public function cancelSubscription(Request $request, MemberSubscription $subscription): JsonResponse
    {
        $this->subscriptionCancelAction->run($subscription, $request->user());

        return $this->respond('Subscription cancelled successfully.');
    }

    /**
     * Queue the bulk expiry warning run — the API twin of the dashboard
     * button, same Action, so neither channel decides the window alone.
     * No request data to read: the route's `can:members.notify` is the gate.
     */
    public function broadcastExpiry(Request $request): JsonResponse
    {
        $this->broadcastAction->run($request->user() ?? abort(403));

        return $this->respond('Expiry notifications queued successfully.');
    }

    /**
     * Put a profile in the trash. The login account is untouched.
     */
    public function destroy(Request $request, Member $member): JsonResponse
    {
        $this->deleteAction->run($member, $request->user());

        return $this->respond('Member moved to trash successfully.');
    }

    /**
     * Restore a trashed profile.
     *
     * 404 for a live row: `{member}` resolves withTrashed on both channels, so
     * the guard is what keeps a restore from repeating an action that already
     * happened (and writing a second audit row for it).
     */
    #[Response(status: 404, type: ApiError::DOC_TYPE)]
    public function restore(Request $request, Member $member): JsonResponse
    {
        abort_unless($member->trashed(), 404);

        $this->restoreAction->run($member, $request->user());

        return $this->respond('Member restored successfully.', 200, [
            'member' => new MemberResource($member->fresh()),
        ]);
    }

    /**
     * Permanently delete a trashed profile, ledger included.
     */
    #[Response(status: 404, type: ApiError::DOC_TYPE)]
    public function forceDelete(Request $request, Member $member): JsonResponse
    {
        abort_unless($member->trashed(), 404);

        $this->forceDeleteAction->run($member, $request->user());

        return $this->respond('Member permanently deleted.');
    }

    /**
     * Put a profile back in the programme.
     */
    #[Response(status: 404, type: ApiError::DOC_TYPE)]
    public function activate(Request $request, Member $member): JsonResponse
    {
        abort_if($member->trashed(), 404);

        $this->activateAction->run($member, $request->user());

        return $this->respond('Member activated successfully.');
    }

    /**
     * Take a profile out of the programme.
     *
     * `violation` decides whether the paid period is forfeited or paused for a
     * later return — the same switch the web form offers.
     */
    #[Response(status: 404, type: ApiError::DOC_TYPE)]
    public function deactivate(MemberDeactivateRequest $request, Member $member): JsonResponse
    {
        abort_if($member->trashed(), 404);

        $this->deactivateAction->run(
            $member,
            (bool) $request->validated('violation'),
            $request->validated('reason'),
            $request->user(),
        );

        return $this->respond('Member deactivated successfully.');
    }

    /**
     * Act on several profiles at once.
     *
     * No `->can()` on the route, matching every other bulk endpoint: the
     * permission depends on the action in the body, so `BulkMemberRequest` maps
     * it (`members.delete` / `members.restore` / `members.force_delete`).
     */
    public function bulkAction(BulkMemberRequest $request): JsonResponse
    {
        $result = $this->bulkProcessor->run(
            action: $request->validated('action'),
            ids: $request->validated('member_ids'),
            causer: $request->user() ?? abort(403),
            handler: $this->bulkHandler,
        );

        return $this->respond("{$result['label']} ({$result['count']} members).");
    }
}
