<?php

namespace Tests;

use App\Models\SystemSetting;
use Database\Seeders\FeatureFlagSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedFeatureFlags();
        $this->fakeNotifications();
    }

    /**
     * Faked by default, so a test about something else does not render mail.
     *
     * Sixteen test files save system settings, and every save now dispatches an
     * administrative notification to the holders of the matching permission. None
     * of those tests is about notifications, and without this they each rendered
     * the Markdown mail template for a real `mail` transport — which is not slow,
     * it is fatal: the run died with a premature end of PHP process partway
     * through the numeric-bound suite.
     *
     * One line in the shared base rather than sixteen: the cost belongs to
     * EVERY test that triggers an action with a notification side effect, and
     * that set only grows. A test that cares about the notification calls
     * `Notification::fake()` itself, or opts out below if it needs a real
     * transport — which no test in this repository does.
     */
    private function fakeNotifications(): void
    {
        if ($this->shouldFakeNotifications()) {
            Notification::fake();
        }
    }

    /**
     * Does this test want notifications faked? Override to opt out.
     */
    protected function shouldFakeNotifications(): bool
    {
        return true;
    }

    /**
     * Drop the settings cache between tests.
     *
     * `SystemSetting` reads through `Cache::rememberForever`, and
     * `RefreshDatabase` rolls the database back but not the cache — so a value
     * one test writes is still there for the next one. That is not theoretical:
     * `RegistrationDefaultRoleTest` clears `registration_default_role`, and
     * `RbacRoleSyncTest` then created an account on no role at all and failed
     * only when the whole `--filter=Role` suite ran together. A test asserting
     * against a leaked value passes alone and fails in CI, which is the worst
     * way for a test to be wrong.
     *
     * One line in the shared base rather than a `bustCache()` call per test file:
     * the leak is a property of the base trait, so the fix belongs beside it.
     */
    protected function tearDown(): void
    {
        SystemSetting::bustCache();

        parent::tearDown();
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
