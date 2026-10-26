<?php

namespace App\Support\OpenApi;

use Dedoc\Scramble\Extensions\OperationExtension;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\RouteInfo;

/**
 * States the route's authorization requirement in the operation description.
 *
 * `docs/planning/phase-12-api-v1.md` § *The mandatory documentation step*, item 7
 * asks for `can:*` / `feature:*` in the description. The route carries them as
 * middleware and Scramble reads neither, so the document showed a gated endpoint
 * as if anyone with a token could call it — 56 of 59 operations before this.
 *
 * `gatherMiddleware()` answers the registered aliases (`can:users.view`), not
 * class names, which is why the two prefixes are matched directly. Operation
 * extensions run after the package's own, so the description they built is
 * already there to append to. Nothing is added for a route that carries neither:
 * public auth, the health probe and self-scoped reads have no requirement to
 * state.
 */
class AuthorizationDescriptionExtension extends OperationExtension
{
    private const REQUIREMENTS = ['can:', 'feature:'];

    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        $requirements = [];

        foreach ($routeInfo->route->gatherMiddleware() as $middleware) {
            foreach (self::REQUIREMENTS as $prefix) {
                if (str_starts_with((string) $middleware, $prefix)) {
                    $requirements[] = (string) $middleware;
                }
            }
        }

        if ($requirements === []) {
            return;
        }

        $operation->description = trim($operation->description."\n\n**Authorization:** "
            .implode(', ', array_map(static fn (string $requirement): string => '`'.$requirement.'`', array_unique($requirements))));
    }
}
