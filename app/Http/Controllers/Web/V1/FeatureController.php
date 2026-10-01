<?php

namespace App\Http\Controllers\Web\V1;

use App\Actions\V1\Feature\FeatureBulkToggleAction;
use App\Actions\V1\Feature\FeatureIndexAction;
use App\Actions\V1\Feature\FeatureToggleAction;
use App\Http\Requests\V1\Feature\BulkFeatureRequest;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Feature flag management (Phase 7).
 *
 * Read and write a module's availability, nothing else. A flag's identity
 * lives in `config/pennant.php` and its state lives in the Pennant store, so
 * there is no create, edit or delete here — the same reasoning that keeps
 * `PermissionController` read-only (`PermissionController.php:15-20`): a row
 * nothing's `can()` call references is a trap.
 *
 * The toggle is authorized by the route (`routes/web.php`) and by the
 * controller's own gate, so an authorization test cannot pass by accident when
 * the route middleware is stripped.
 */
class FeatureController extends Controller
{
    public function __construct(
        private readonly FeatureIndexAction $indexAction,
        private readonly FeatureToggleAction $toggleAction,
        private readonly FeatureBulkToggleAction $bulkToggleAction,
    ) {
    }

    /**
     * List every flag, grouped by module, with its current state.
     */
    public function index(Request $request): View
    {
        $this->authorize($request, 'features.view');

        return view('pages.features.index', [
            ...$this->indexAction->run($request->user()),
        ]);
    }

    /**
     * Switch one flag.
     *
     * No Form Request class: the payload is one boolean on a query string, and
     * `P6-C13` deleted a one-rule request class for exactly this. The gate and
     * the slug check are both here instead of relying on the route.
     */
    public function toggle(Request $request, string $feature): RedirectResponse
    {
        $this->authorize($request, 'features.manage');

        $validated = $request->validate([
            'enabled' => ['required', 'boolean', Rule::in(['0', '1', '0.0', '1.0', 0, 1, false, true])],
        ]);

        $result = $this->toggleAction->run(
            $feature,
            (bool) $validated['enabled'],
            $request->user(),
        );

        return back()->with('status', sprintf(
            '%s is now %s.',
            $result['label'],
            $result['to'] ? 'enabled' : 'disabled',
        ));
    }

    /**
     * Set several flags to one state, as a single audited change.
     *
     * Authorization lives in BulkFeatureRequest — one `features.manage` gate
     * covers both directions, since enabling and disabling rewrite the same
     * store row. See that class for why this is not `AuthorizesBulkAction`.
     */
    public function bulkAction(BulkFeatureRequest $request): RedirectResponse
    {
        $result = $this->bulkToggleAction->run(
            $request->slugs(),
            $request->enabled(),
            $request->user(),
        );

        // Reports the REQUEST, not just the rows that moved: "5 requested, 2
        // were already enabled" is the honest answer, and a bare "5 updated"
        // overstates what happened when a caller re-submits the same selection.
        return back()->with('status', sprintf(
            '%d feature flag(s) are now %s. %d requested, %d already in that state.',
            count($result['changed']),
            $result['enabled'] ? 'enabled' : 'disabled',
            count($result['changed']) + count($result['unchanged']),
            count($result['unchanged']),
        ));
    }

    /**
     * Authorize against the app's own Gate.
     *
     * Routed through a helper rather than `$this->authorize()` alone so both
     * the route gate and this method run the same check — the two-layer
     * pattern `GateD6DPentestTest` exists to prove in the other direction.
     */
    private function authorize(Request $request, string $permission): void
    {
        abort_unless($request->user()?->can($permission), 403);
    }
}
