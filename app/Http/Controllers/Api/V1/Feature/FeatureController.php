<?php

namespace App\Http\Controllers\Api\V1\Feature;

use App\Actions\V1\Feature\FeatureBulkToggleAction;
use App\Actions\V1\Feature\FeatureIndexAction;
use App\Actions\V1\Feature\FeatureToggleAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Feature\BulkFeatureRequest;
use App\Http\Requests\V1\Feature\ToggleFeatureRequest;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API feature flag controller: read the catalogue, toggle one flag, or set
 * several at once.
 *
 * The web counterpart returns redirects and a flash message; the actions and the
 * gates behind them are the same, so both channels share BulkFeatureRequest and
 * the FeatureToggleAction / FeatureBulkToggleAction pair. Only the response
 * shape differs.
 *
 * `index` exists because the write side without it is read-blind: a client could
 * change a flag it could not enumerate, and the only way to learn a flag's state
 * was to toggle it — a mutation plus an audit row as a read. The list is the same
 * resolved catalogue the web page reads, projected to the fields a client can act
 * on (see `index()` for what is deliberately dropped).
 */
class FeatureController extends Controller
{
    /**
     * @param  FeatureIndexAction  $indexAction
     * @param  FeatureToggleAction  $toggleAction
     * @param  FeatureBulkToggleAction  $bulkToggleAction
     */
    public function __construct(
        private readonly FeatureIndexAction $indexAction,
        private readonly FeatureToggleAction $toggleAction,
        private readonly FeatureBulkToggleAction $bulkToggleAction,
    ) {
    }

    /**
     * The flag catalogue with its current state.
     *
     * Projected from `FeatureIndexAction`, not returned whole: that action also
     * carries a web `toggle_url`, the viewer's `manageable` bit and the
     * permission names a flag owns. The first is a link a JSON client must not
     * follow (it POSTs a browser form and needs session + CSRF), the second is
     * a UI affordance, and the third is management copy. Toggling over the API
     * is `POST /features/{feature}/toggle`.
     *
     * `pending` is not decoration: it marks a flag whose module has no routes
     * yet, so a client does not report "disabled" for a switch that controls
     * nothing.
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    #[Response(status: 200, type: 'array{data: array{features: array<int, array{slug: string, label: string, group: string, description: string, enabled: bool, pending: string|null}>}, meta: array{request_id: string, timestamp: string}}')]
    public function index(Request $request): JsonResponse
    {
        $catalogue = $this->indexAction->run($request->user());

        $features = array_map(
            fn (array $row): array => [
                'slug' => $row['slug'],
                'label' => $row['label'],
                'group' => $row['group'],
                'description' => $row['description'],
                'enabled' => $row['enabled'],
                'pending' => $row['pending'],
            ],
            array_merge(...array_values($catalogue['featureGroups'])),
        );

        return $this->respond('', 200, ['features' => $features]);
    }

    /**
     * Turn one flag on or off.
     *
     * Validation and authorization live in `ToggleFeatureRequest`, shared with
     * the web channel. The slug is not validated there: the route parameter is a
     * plain string, and `FeatureToggleAction` resolves it through
     * `FeatureCatalog` and 404s on an undeclared name on both channels.
     */
    public function toggle(ToggleFeatureRequest $request, string $feature): JsonResponse
    {
        $result = $this->toggleAction->run($feature, (bool) $request->validated('enabled'), $request->user());

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
