<?php

namespace Tests\Unit\Support;

use App\Support\FeatureCatalog;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;
use Tests\TestCase;

/**
 * `App\Support\FeatureCatalog` reads `config/pennant.php` and owns the ONE
 * answer to "is this module on". The two failures it exists to prevent are both
 * silent: a reader that calls `Feature::active()` directly skips the config kill
 * switch, and a per-slug lookup on a page that renders every flag costs one
 * query per flag.
 *
 * Its state is not its data: identity is config, state is Pennant's store. So
 * every test here sets up the store explicitly instead of assuming a value.
 */
class FeatureCatalogTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every consumer reads a row and expects all five keys — a bare
     * `'audit' => []` declaration must still render rather than leave the page
     * null-checking.
     */
    public function test_every_row_is_fully_populated_and_keyed_by_its_slug(): void
    {
        $this->assertNotEmpty(FeatureCatalog::slugs());

        $this->assertSame(FeatureCatalog::slugs(), array_keys(FeatureCatalog::all()));

        foreach (FeatureCatalog::all() as $slug => $row) {
            $this->assertSame($slug, $row['slug']);
            $this->assertArrayHasKey('label', $row);
            $this->assertArrayHasKey('description', $row);
            $this->assertArrayHasKey('pending', $row);
            $this->assertNotSame('', $row['label'], "[{$slug}] has no label to show an operator");
            $this->assertNotSame('', $row['group'], "[{$slug}] has no group, so it lands under no heading");
            $this->assertTrue($row['pending'] === null || $row['pending'] !== '', "[{$slug}] has an empty pending note");
        }
    }

    public function test_grouping_loses_no_flag_and_invents_no_group(): void
    {
        $flattened = [];

        foreach (FeatureCatalog::grouped() as $group => $rows) {
            $this->assertNotEmpty($rows, "group `{$group}` is empty");

            foreach ($rows as $row) {
                $this->assertSame($group, $row['group'], "[{$row['slug']}] is filed under the wrong heading");
                $flattened[$row['slug']] = $row;
            }
        }

        $this->assertSame(FeatureCatalog::all(), $flattened, 'the grouping dropped or duplicated a flag');
    }

    /**
     * A slug that is not declared must never be treated as one of ours — the
     * toggle action leans on `has()` to refuse writing a row nothing reads.
     */
    public function test_an_undeclared_slug_is_not_found_and_not_ours(): void
    {
        $this->assertNull(FeatureCatalog::find('no_such_flag'));
        $this->assertFalse(FeatureCatalog::has('no_such_flag'));
        $this->assertFalse(FeatureCatalog::isActive('no_such_flag'), 'an undeclared flag must fail closed');

        $this->assertNotNull(FeatureCatalog::find('users'));
        $this->assertTrue(FeatureCatalog::has('users'));
    }

    /**
     * The kill switch has to be applied per slug INSIDE `activeMap()`, not by
     * handing the raw store map back: the store keeps the operator's `true`, and
     * a config-disabled flag must read false without asking the store at all.
     */
    public function test_a_config_disabled_flag_is_false_in_the_batch_read_without_touching_the_store(): void
    {
        $this->assertSame(
            ['pulse' => true, 'users' => true],
            FeatureCatalog::activeMap(['pulse', 'users']),
            'fixture: every flag is seeded active in tests'
        );

        config(['pennant.features.pulse.disabled' => true]);

        $this->assertSame(
            ['pulse' => false, 'users' => true],
            FeatureCatalog::activeMap(['pulse', 'users']),
            'activeMap ignored the kill switch, or applied it to the wrong slug'
        );

        $this->assertTrue(
            Feature::active('pulse'),
            'precondition: the store still holds `true`; the false above must come from config'
        );

        $this->assertFalse(FeatureCatalog::isActive('pulse'), 'isActive() and activeMap() disagree');
    }

    public function test_the_batch_read_answers_for_every_slug_it_is_given(): void
    {
        $slugs = FeatureCatalog::slugs();

        $this->assertSame($slugs, array_keys(FeatureCatalog::activeMap($slugs)));

        $this->assertSame(
            ['no_such_flag' => false],
            FeatureCatalog::activeMap(['no_such_flag']),
            'an undeclared slug in the batch read must fail closed rather than vanish'
        );
    }

    /**
     * The settings a flag owns are what the settings page hides and the update
     * action refuses when the module is off. A key no setting defines is a
     * declaration about nothing — the field could never render either way.
     */
    public function test_every_setting_a_flag_owns_is_a_real_setting(): void
    {
        $this->seed(SystemSettingSeeder::class);

        $defined = DB::table('system_settings')->pluck('key')->all();
        $groups = FeatureCatalog::settingGroups();

        $this->assertNotEmpty($groups, 'no flag declares settings, so the map is untested');

        foreach ($groups as $slug => $group) {
            $this->assertTrue(FeatureCatalog::has($slug), "setting group `{$slug}` belongs to an undeclared flag");
            $this->assertNotEmpty($group['keys'], "[{$slug}] claims a settings group with no keys");
            $this->assertSame(array_values(array_unique($group['keys'])), $group['keys'], "[{$slug}] lists a key twice");

            foreach ($group['keys'] as $key) {
                $this->assertContains($key, $defined, "[{$slug}] owns `{$key}`, which no setting defines");
            }

            $this->assertTrue($group['active'], "[{$slug}] should be active — every flag is seeded on here");
        }
    }

    public function test_a_disabled_flags_setting_group_reads_inactive(): void
    {
        $this->seed(SystemSettingSeeder::class);

        $this->assertArrayHasKey('users', FeatureCatalog::settingGroups());

        config(['pennant.features.users.disabled' => true]);

        $this->assertFalse(
            FeatureCatalog::settingGroups()['users']['active'],
            'a config-disabled module still offers its settings for editing'
        );
    }
}
