<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Laravel\Pennant\Feature;
use Laravel\Pennant\FeatureManager;
use Tests\TestCase;

class PennantFoundationTest extends TestCase
{
    /**
     * PennantServiceProvider is auto-discovered and the FeatureManager
     * is resolvable from the container.
     */
    public function test_pennant_service_is_registered(): void
    {
        $this->assertInstanceOf(FeatureManager::class, app(FeatureManager::class));
    }

    /**
     * The features table migration is published (not a custom migration).
     */
    public function test_features_migration_is_published(): void
    {
        $migrations = glob(database_path('migrations/*_create_features_table.php'));

        $this->assertNotEmpty(
            $migrations,
            'Expected a Pennant features migration in database/migrations/.'
        );
    }

    /**
     * A feature can be defined and checked using the array driver
     * (no database table required for the foundation test).
     */
    public function test_feature_can_be_defined_and_checked(): void
    {
        config()->set('pennant.default', 'array');

        Feature::define('foundation-demo', fn () => true);
        $this->assertTrue(Feature::active('foundation-demo'));

        Feature::define('foundation-demo-off', fn () => false);
        $this->assertFalse(Feature::active('foundation-demo-off'));
    }

    /**
     * The @feature Blade directive is registered and functional.
     */
    public function test_feature_blade_directive_is_registered(): void
    {
        config()->set('pennant.default', 'array');

        Feature::define('foundation-blade-on', fn () => true);
        $html = Blade::render("@feature('foundation-blade-on') visible @endfeature");
        $this->assertStringContainsString('visible', $html);

        Feature::define('foundation-blade-off', fn () => false);
        $html = Blade::render("@feature('foundation-blade-off') visible @endfeature");
        $this->assertStringNotContainsString('visible', $html);
    }
}
