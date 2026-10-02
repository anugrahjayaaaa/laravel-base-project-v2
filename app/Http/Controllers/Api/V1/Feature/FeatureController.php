<?php

namespace App\Http\Controllers\Api\V1\Feature;

use App\Actions\V1\Feature\FeatureBulkToggleAction;
use App\Actions\V1\Feature\FeatureToggleAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Feature\BulkFeatureRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * API feature flag controller: toggle one flag, or set several at once.
 *
 * The web counterpart returns redirects and a flash message; the actions and the
 * gates behind them are the same, so both channels share BulkFeatureRequest and
 * the FeatureToggleAction / FeatureBulkToggleAction pair. Only the response
 * shape differs.
 *
 * There is deliberately no `index`. A caller that may toggle a flag can be told
 * which flags exist and what state they are in; the catalogue is eight slugs
 * of config, so a listing adds a surface without adding information a toggling
 * client does not already have.
 */
class FeatureController extends Controller
{
    /**
     * @param  FeatureToggleAction  $toggleAction
     * @param  FeatureBulkToggleAction  $bulkToggleAction
     */
    public function __construct(
        private readonly FeatureToggleAction $toggleAction,
        private readonly FeatureBulkToggleAction $bulkToggleAction,
    ) {
    }

    /**
     * Turn one flag on or off.
     *
     * The rules match the web controller's inline validation deliberately. The
     * slug is not validated here: the route parameter is a plain string, and
     * FeatureToggleAction resolves it through FeatureCatalog and 404s on an
     * undeclared name, which is the same answer the web channel gives.
     */
    public function toggle(Request $request, string $feature): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean', Rule::in(['0', '1', '0.0', '1.0', 0, 1, false, true])],
        ]);

        $result = $this->toggleAction->run($feature, (bool) $validated['enabled'], $request->user());

        return $this->respond(sprintf(
            '%s is now %s.',
            $result['label'],
            $result['to'] ? 'enabled' : 'disabled',
        ), 200, ['feature' => $result]);
    }

    /**
     * Set several flags to one state, as a single audited change.
     *
     * Reports changed and unchanged rather than a bare count: "5 requested, 2
     * were already enabled" is the honest answer when a caller re-submits the
     * same selection.
     */
    public function bulkAction(BulkFeatureRequest $request): JsonResponse
    {
        $result = $this->bulkToggleAction->run(
            $request->slugs(),
            $request->enabled(),
            $request->user(),
        );

        return $this->respond(sprintf(
            '%d feature flag(s) are now %s. %d requested, %d already in that state.',
            count($result['changed']),
            $result['enabled'] ? 'enabled' : 'disabled',
            count($result['changed']) + count($result['unchanged']),
            count($result['unchanged']),
        ), 200, [
            'changed' => $result['changed'],
            'unchanged' => $result['unchanged'],
            'enabled' => $result['enabled'],
        ]);
    }
}
