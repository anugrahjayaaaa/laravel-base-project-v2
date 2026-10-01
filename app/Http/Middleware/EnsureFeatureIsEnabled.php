<?php

namespace App\Http\Middleware;

use App\Support\FeatureCatalog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuse a request whose module flag is off, with 403.
 *
 * ## Why this exists rather than Pennant's `EnsureFeaturesAreActive`
 *
 * One architectural reason, plus the status this project settles on.
 *
 * **Not 400.** Pennant's middleware aborts 400
 * (`EnsureFeaturesAreActive.php:32`). 400 says "your request is malformed",
 * which sends an integrator hunting a bug in their own code when the request
 * was in fact well-formed and the server declined to serve it.
 *
 * **It cannot read `disabled => true`.** Pennant resolves through
 * `Feature::active()`, which asks the store. A `disabled => true` flag with a
 * stored `true` row therefore reads ACTIVE to it — measured:
 *
 *     config(['pennant.features.users.disabled' => true]);
 *     FeatureCatalog::isActive('users')  => false   ← correct
 *     Feature::active('users')          => true    ← Pennant's view
 *     Feature::someAreInactive(['users']) => false  ← what its middleware sees
 *
 * So aliasing Pennant's class would leave the config kill switch inert on every
 * gated route. Going through `FeatureCatalog::isActive()` is what makes both the
 * store state and the config override answer the same question.
 *
 * ## No `features.manage` bypass
 *
 * There is deliberately no permission check here. A flag off refuses everyone,
 * managers included, and a manager re-enables from `/features` first. A kill
 * switch the superadmin can walk through is not a kill switch, and "the feature
 * is off but the CEO can still see it" is a state nobody asked for.
 *
 * Fail-closed by construction: an undeclared slug is not in the catalogue, and
 * `Feature::active()` for a slug with no store row is `false`.
 *
 * ## Why 403, not 404
 *
 * Settled 2026-10-01, changing an earlier 404. A disabled module is refused the
 * same way an unpermitted one is: 403 Forbidden. This matches what the admin
 * already sees everywhere else — `can:` returns 403, `CheckAccountState` returns
 * 403 — so one status means "you may not have this" across the whole admin, and
 * an integrator needs no table of which refusal means what.
 *
 * The cost, stated plainly: 403 does not distinguish *module killed* from *no
 * permission* from *account disabled*. A client that needs that distinction must
 * ask `/features` (which stays ungated) rather than infer it from the status.
 * Chosen deliberately — an operator-facing admin console gains little from
 * obscurity, and the flag's existence was never secret.
 *
 * **400 and 404 are both wrong for different reasons.** 400 blames the caller's
 * request, which was fine. 404 claims the route does not exist, which is false
 * and leaves a support trail of "this page 404s intermittently". 403 states the
 * one true thing: the server understood, and will not serve it.
 *
 * @see \App\Support\FeatureCatalog::isActive()
 */
class EnsureFeatureIsEnabled
{
    /**
     * Refuse the request unless every named flag is active.
     *
     * Variadic so one route can require several flags. Laravel splits a
     * middleware's parameters on the FIRST colon and then on commas, so the
     * alias is written ONCE: `feature:users,roles` yields ['users','roles'].
     * Repeating it — `feature:users,feature:roles` — yields the literal string
     * 'feature:roles', which is an undeclared slug and therefore fails closed
     * into a 403 that looks like a working kill switch.
     *
     * @param  Request  $request
     * @param  Closure  $next
     * @param  string  ...$features  Flag slugs, checked with AND semantics.
     * @return Response
     */
    public function handle(Request $request, Closure $next, string ...$features): Response
    {
        foreach ($features as $feature) {
            if (! FeatureCatalog::isActive($feature)) {
                abort(403);
            }
        }

        return $next($request);
    }
}
