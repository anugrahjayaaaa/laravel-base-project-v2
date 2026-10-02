<?php

namespace Tests;

use Database\Seeders\FeatureFlagSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedFeatureFlags();
    }

    /**
     * Baseline every test starts from: every declared flag ACTIVE.
     *
     * P7-D7/D8 gate `users`, `roles`, `permissions`, `settings` and `sessions`
     * behind `feature:{slug}`, so a test that visits one of those routes without
     * a seeded flag gets 403 from the middleware — 274 tests did, none of them
     * about feature flags. Declaring does not activate (the Pennant trap), and
     * a fresh install runs FeatureFlagSeeder, so "all active" is the state a
     * real deployment is in. A test that wants a flag OFF says so by switching
     * it off, which is the direction that matters and the one worth asserting.
     *
     * One file rather than 74: the alternative is every test that touches a
     * gated route carrying a seeder call, which is the churn this avoids.
     *
     * A class overrides `shouldSeedFeatureFlags()` to opt out — FeatureFlagCatalogTest
     * does, because asserting an UNSEEDED database is its whole purpose.
     */
    private function seedFeatureFlags(): void
    {
        if (! $this->shouldSeedFeatureFlags()) {
            return;
        }

        // Guarded on the table existing: RefreshDatabase runs the migrations,
        // and the handful of tests that do not use it (pure unit and
        // static-analysis checks) have no `features` table to seed.
        if (in_array(RefreshDatabase::class, class_uses_recursive(static::class), true)
            && Schema::hasTable('features')) {
            $this->seed(FeatureFlagSeeder::class);
        }
    }

    /**
     * Does this test start from every flag active? Override to opt out.
     */
    protected function shouldSeedFeatureFlags(): bool
    {
        return true;
    }
}
