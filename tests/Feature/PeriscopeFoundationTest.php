<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Route;
use Laravel\Telescope\Telescope;
use Tests\TestCase;

class PeriscopeFoundationTest extends TestCase
{
    /**
     * Periscope companion UI routes are registered alongside Telescope.
     * Periscope is not a replacement — routes register independently.
     */
    public function test_periscope_routes_are_registered(): void
    {
        $names = collect(Route::getRoutes())->map->getName();

        $this->assertTrue($names->contains('periscope.index'));
        $this->assertTrue($names->contains('periscope.entries.index'));
    }

    /**
     * Periscope service provider is auto-discovered and loaded.
     */
    public function test_periscope_service_provider_is_registered(): void
    {
        $this->assertArrayHasKey(
            'TortoiseIT\\LaravelPeriscope\\LaravelPeriscopeServiceProvider',
            App::getLoadedProviders()
        );
    }

    /**
     * /periscope is reachable (route exists; 403 = Telescope auth gate working,
     * same authorization mechanism Telescope itself uses).
     */
    public function test_periscope_route_is_reachable(): void
    {
        $response = $this->get('/periscope');
        $this->assertContains($response->status(), [200, 403]);
    }

    /**
     * Telescope package is still installed and intact (Periscope is a companion,
     * not a replacement).
     */
    public function test_telescope_is_still_installed(): void
    {
        $this->assertTrue(class_exists(Telescope::class));
        $this->assertNotNull(config('telescope'));
    }
}
