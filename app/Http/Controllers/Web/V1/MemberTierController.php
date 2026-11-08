<?php

namespace App\Http\Controllers\Web\V1;

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
use App\Models\MemberTier;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Tier configuration screens (Phase 18, P18-B6).
 *
 * The list is an Action's job. The two form pages hold no data of their own
 * beyond the role picker and the currency symbol, so they assemble their view
 * model here — the same shape `MemberController::create()` uses, because the D-4
 * contract is about the Blade file not resolving URLs, not about where the
 * array is built.
 */
class MemberTierController extends Controller
{
    public function __construct(
        private readonly MemberTierIndexAction $indexAction,
        private readonly MemberTierCreateAction $createAction,
        private readonly MemberTierUpdateAction $updateAction,
        private readonly MemberTierActivateAction $activateAction,
        private readonly MemberTierDeactivateAction $deactivateAction,
        private readonly MemberTierDeleteAction $deleteAction,
        private readonly MemberTierRestoreAction $restoreAction,
        private readonly MemberTierForceDeleteAction $forceDeleteAction,
        private readonly BulkActionProcessor $bulkProcessor,
        private readonly MemberTierBulkActionHandler $bulkHandler,
    ) {
    }

    public function index(MemberTierQueryRequest $request): View
    {
        return view('pages.members.tiers', $this->indexAction->run(
            search: $request->validated('search'),
            status: $request->validated('status') ?? 'active',
        ));
    }

    public function create(): View
    {
        return view('pages.members.tier-form', $this->formData() + [
            'formUrl' => route('members.tiers.store'),
            'formMethod' => 'POST',
            'tier' => null,
            // Nothing to delete yet: the Danger Zone card hides rather than
            // render a button pointing at a row that does not exist.
            'deleteUrl' => null,
        ]);
    }

    public function edit(MemberTier $tier): View
    {
        return view('pages.members.tier-form', $this->formData() + [
            'formUrl' => route('members.tiers.update', $tier),
            'formMethod' => 'PUT',
            'tier' => $tier,
            'deleteUrl' => route('members.tiers.destroy', $tier),
        ]);
    }

    public function store(MemberTierRequest $request): RedirectResponse
    {
        $tier = $this->createAction->run($request->validated(), $request->user());

        return redirect()->route('members.tiers')
            ->with('status', "Tier {$tier->name} created.");
    }

    public function update(MemberTierRequest $request, MemberTier $tier): RedirectResponse
    {
        $this->updateAction->run($tier, $request->validated(), $request->user());

        return redirect()->route('members.tiers')
            ->with('status', "Tier {$tier->name} updated.");
    }

    /**
     * Switch a tier on without opening its form.
     */
    public function activate(Request $request, MemberTier $tier): RedirectResponse
    {
        $this->activateAction->run($tier, $request->user());

        return redirect()->route('members.tiers', ['status' => 'active'])
            ->with('status', "Tier {$tier->name} activated.");
    }

    /**
     * Retire a tier without trashing it.
     *
     * Subscriptions already on it keep running — which is exactly the case
     * `MemberTierDeleteAction` refuses to trash, reached without editing the row.
     */
    public function deactivate(Request $request, MemberTier $tier): RedirectResponse
    {
        $this->deactivateAction->run($tier, $request->user());

        return redirect()->route('members.tiers', ['status' => 'inactive'])
            ->with('status', "Tier {$tier->name} deactivated.");
    }

    /**
     * Trash a tier.
     *
     * No FormRequest: there is nothing to validate beyond the route model, and
     * `MemberTierDeleteAction` throws the one refusal (a tier still in use) as a
     * `ValidationException`, which sends the operator back with that message on
     * the form field the modal has no opinion about.
     */
    public function destroy(Request $request, MemberTier $tier): RedirectResponse
    {
        $this->deleteAction->run($tier, $request->user());

        return redirect()->route('members.tiers')
            ->with('status', "Tier {$tier->name} moved to the trash.");
    }

    /**
     * Restore a trashed tier.
     *
     * Raw id + `onlyTrashed()`: the `{tier}` binding resolves through the
     * SoftDeletes scope, so it 404s exactly the rows these two routes exist for.
     */
    public function restore(Request $request, int $tier): RedirectResponse
    {
        $trashed = $this->findTrashed($tier);

        $this->restoreAction->run($trashed, $request->user());

        return redirect()->route('members.tiers', ['status' => 'active'])
            ->with('status', "Tier {$trashed->name} restored.");
    }

    /**
     * Permanently delete a trashed tier.
     */
    public function forceDelete(Request $request, int $tier): RedirectResponse
    {
        $trashed = $this->findTrashed($tier);
        $name = $trashed->name;

        $this->forceDeleteAction->run($trashed, $request->user());

        return redirect()->route('members.tiers', ['status' => 'trashed'])
            ->with('status', "Tier {$name} permanently deleted.");
    }

    public function bulkAction(BulkMemberTierRequest $request): RedirectResponse
    {
        $result = $this->bulkProcessor->run(
            action: $request->validated('action'),
            ids: $request->validated('tier_ids'),
            // `auth` middleware guarantees the caller; the type does not know it.
            causer: $request->user() ?? abort(403),
            handler: $this->bulkHandler,
        );

        return back()->with('status', "{$result['count']} selected {$result['label']}.");
    }

    /**
     * Resolve a route id to a trashed tier, or 404.
     */
    private function findTrashed(int $id): MemberTier
    {
        return MemberTier::onlyTrashed()->findOrFail($id);
    }

    /**
     * The parts both form pages need.
     *
     * @return array<string, mixed>
     */
    private function formData(): array
    {
        return [
            'indexUrl' => route('members.index'),
            'tiersUrl' => route('members.tiers'),
            // Built by `MemberTier::assignableRoleOptions()` — the set the Form
            // Request validates against, so a role in the list is a role that
            // saves, and `superadmin` is in neither.
            'roleOptions' => MemberTier::assignableRoleOptions(),
            'currencySymbol' => Money::symbol(),
        ];
    }
}
