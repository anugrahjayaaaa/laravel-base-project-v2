<?php

namespace App\Support\OpenApi;

use Dedoc\Scramble\SecurityDocumentation\MiddlewareAuthSecurityStrategy;
use Dedoc\Scramble\Support\Generator\SecurityScheme;

/**
 * The package's middleware-matched strategy, with the bearer scheme named.
 *
 * `config:cache` var_exports every config leaf, so `config/scramble.php` cannot
 * hold a live `SecurityScheme` object — P17-C2 found `php artisan optimize`
 * dying on `scramble.security_strategy.1.scheme` because the scheme was built
 * in config. Building it here keeps config scalar (enforced by
 * `tests/Arch/SerializableConfigTest.php`) while the document still prints
 * `bearerAuth` next to the Authorize button, the name `ApiDocumentationTest`
 * pins and `docs/base/api/documentation.md` documents.
 *
 * The defaults mirror `MiddlewareAuthSecurityStrategy::__construct()` exactly;
 * only the scheme's name is added, so `app()` can still inject the options
 * array from config without knowing this class exists.
 */
class BearerAuthSecurityStrategy extends MiddlewareAuthSecurityStrategy
{
    /**
     * @param  list<string>  $middleware
     */
    public function __construct(array $middleware = ['auth', 'auth:*'], ?SecurityScheme $scheme = null)
    {
        parent::__construct($middleware, $scheme ?? SecurityScheme::http('bearer')->as('bearerAuth'));
    }
}
