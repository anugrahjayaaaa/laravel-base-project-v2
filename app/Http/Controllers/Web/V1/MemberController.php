<?php

namespace App\Http\Controllers\Web\V1;

use App\Actions\V1\BulkAction\BulkActionProcessor;
use App\Actions\V1\Member\MemberAdjustPointsAction;
use App\Actions\V1\Member\MemberActivateAction;
use App\Actions\V1\Member\MemberBulkActionHandler;
use App\Actions\V1\Member\MemberCreateAction;
use App\Actions\V1\Member\MemberDeactivateAction;
use App\Actions\V1\Member\MemberDeleteAction;
use App\Actions\V1\Member\MemberForceDeleteAction;
use App\Actions\V1\Member\MemberIndexAction;
use App\Actions\V1\Member\MemberRestoreAction;
use App\Actions\V1\Member\MemberShowAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Member\BulkMemberRequest;
use App\Http\Requests\V1\Member\MemberCreateRequest;
use App\Http\Requests\V1\Member\MemberDeactivateRequest;
use App\Http\Requests\V1\Member\MemberPointsAdjustRequest;
use App\Http\Requests\V1\Member\MemberQueryRequest;
use App\Models\Member;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Member screens (Phase 18).
 *
 * Thin by construction: the Actions own resolution and decoration, and every
 * URL and label the Blade files render arrives from a view model — the write
 * paths land here beside the reads, they do not move into the views.
 */
class MemberController extends Controller
{
    public function __construct(
        private readonly MemberIndexAction $indexAction,
        private readonly MemberShowAction $showAction,
        private readonly MemberCreateAction $createAction,
        private readonly MemberAdjustPointsAction $pointsAction,
        private readonly MemberActivateAction $activateAction,
        private readonly MemberDeactivateAction $deactivateAction,
        private readonly MemberDeleteAction $deleteAction,
        private readonly MemberRestoreAction $restoreAction,
        private readonly MemberForceDeleteAction $forceDeleteAction,
        private readonly BulkActionProcessor $bulkProcessor,
        private readonly MemberBulkActionHandler $bulkHandler,
    ) {
    }

    public function index(MemberQueryRequest $request): View
    {
        $viewModel = $this->indexAction->run(
            search: $request->validated('search'),
            sort: $request->validated('sort') ?? 'member_number',
            direction: $request->validated('direction') ?? 'asc',
            status: $request->validated('status') ?? 'active',
        );

        $viewModel['filters'] = $request->only('search', 'sort', 'direction', 'status');

        return view('pages.members.index', $viewModel);
    }

    public function show(Member $member): View
    {
        return view('pages.members.show', $this->showAction->run($member));
    }

    /**
     * The Add new member form.
     *
     * No Action: the page holds the two URLs the D-4 contract forbids the Blade
     * file from resolving itself, plus the member number the disabled field
     * shows — `Member::nextMemberNumber()` is the same call the create Action
     * makes, so the preview is the real value, not a guess.
     */
    public function create(): View
    {
        return view('pages.members.create', [
            'createUrl' => route('members.store'),
            'indexUrl' => route('members.index'),
            'memberNumber' => Member::nextMemberNumber(),
        ]);
    }

    public function store(MemberCreateRequest $request): RedirectResponse
    {
        $member = $this->createAction->run($request->validated(), $request->user());

        return redirect()->route('members.show', $member)
            ->with('status', 'Member created. Their verification link is on its way.');
    }

    public function points(MemberPointsAdjustRequest $request, Member $member): RedirectResponse
    {
        $this->pointsAction->run($member, $request->validated(), $request->user());

        return back()->with('status', 'Points adjusted.');
    }

    /**
     * Put a profile back in the programme.
     *
     * Issues no subscription: the member buys the next period themselves or at
     * the cashier, and the days that were paused are already handed back by
     * `MemberActivateAction`.
     */
    public function activate(Request $request, Member $member): RedirectResponse
    {
        abort_if($member->trashed(), 404);

        $this->activateAction->run($member, $request->user());

        return redirect()->route('members.index', ['status' => 'active'])
            ->with('status', "Member {$member->member_number} activated.");
    }

    /**
     * Take a profile out of the programme.
     *
     * `violation` is the sanction switch: forfeit the paid period, or pause it
     * and keep the days for when they come back. The reason travels into the
     * audit row only, never into a column.
     */
    public function deactivate(MemberDeactivateRequest $request, Member $member): RedirectResponse
    {
        abort_if($member->trashed(), 404);

        $this->deactivateAction->run(
            $member,
            (bool) $request->validated('violation'),
            $request->validated('reason'),
            $request->user(),
        );

        return redirect()->route('members.index', ['status' => 'inactive'])
            ->with('status', "Member {$member->member_number} deactivated.");
    }

    public function destroy(Request $request, Member $member): RedirectResponse
    {
        $this->deleteAction->run($member, $request->user());

        return redirect()->route('members.index')
            ->with('status', "Member {$member->member_number} moved to the trash.");
    }

    /**
     * Restore a trashed profile.
     *
     * The `{member}` binding resolves `withTrashed()`, so a live profile reaches
     * here too — the trashed guard is the two-step the force-delete path depends on.
     */
    public function restore(Request $request, Member $member): RedirectResponse
    {
        abort_unless($member->trashed(), 404);

        $this->restoreAction->run($member, $request->user());

        return redirect()->route('members.index', ['status' => 'active'])
            ->with('status', "Member {$member->member_number} restored.");
    }

    /**
     * Permanently delete a trashed profile.
     *
     * Its subscriptions and point history go with it (both FKs cascade); the
     * login account is untouched — that is the users screen's decision.
     */
    public function forceDelete(Request $request, Member $member): RedirectResponse
    {
        abort_unless($member->trashed(), 404);

        $memberNumber = $member->member_number;

        $this->forceDeleteAction->run($member, $request->user());

        return redirect()->route('members.index', ['status' => 'trashed'])
            ->with('status', "Member {$memberNumber} permanently deleted.");
    }

    public function bulkAction(BulkMemberRequest $request): RedirectResponse
    {
        $result = $this->bulkProcessor->run(
            action: $request->validated('action'),
            ids: $request->validated('member_ids'),
            // `auth` middleware guarantees the caller; the type does not know it.
            causer: $request->user() ?? abort(403),
            handler: $this->bulkHandler,
        );

        return back()->with('status', "{$result['count']} selected {$result['label']}.");
    }
}
