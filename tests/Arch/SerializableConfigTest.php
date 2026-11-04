<?php

namespace Tests\Arch;

use Illuminate\Support\Arr;
use UnitEnum;
use Tests\TestCase;

/**
 * No config value may be an object that `config:cache` cannot rebuild.
 *
 * `php artisan optimize` runs `config:cache`, which exports the merged config as
 * PHP source and reads that source back on every boot. `var_export()` renders an
 * object as `Class::__set_state([...])`, and a class that does not define
 * `__set_state()` makes the generated file a fatal error — so an object in a
 * config file is a deploy blocker, not a style preference. The suite could not
 * see it: tests boot the raw config files, so the breakage only ever appeared on
 * a production-shaped host. P17-C2 hit exactly that — `config/scramble.php`
 * built a `SecurityScheme` there and `optimize` died on
 * `scramble.security_strategy.1.scheme`.
 *
 * Enums pass because they export as their own case name. Objects pass only when
 * their class defines `__set_state()` — which is what makes the generated file
 * evaluate back, and why `permission.cache.expiration_time` (a `DateInterval`,
 * an internal class that does define it) is legal while a package's value object
 * is not. A `Closure` never defines it, so closures are caught by the same rule.
 * Scalars and arrays are inert. What the named bearer scheme must still do is
 * owned by `ApiDocumentationTest` (it reads `securitySchemes.bearerAuth` out of
 * the generated document); this file only guards the serializability property,
 * so the two checks have one owner each.
 */
class SerializableConfigTest extends TestCase
{
    public function test_every_config_value_survives_config_caching(): void
    {
        $violations = [];

        foreach (Arr::dot(config()->all()) as $key => $value) {
            if (is_object($value) && ! $value instanceof UnitEnum && ! method_exists($value, '__set_state')) {
                $violations[] = $key.' is a '.get_class($value);
            }
        }

        $this->assertSame(
            [],
            $violations,
            'these config values are objects and cannot be config-cached: '.implode(', ', $violations)
        );
    }
}
