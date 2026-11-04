<?php

namespace Tests\Unit\Actions;

use App\Actions\V1\Permission\PermissionIndexAction;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The permission catalogue query — the read side of the permission screen.
 *
 * Every value here arrives in the query string, so the two fallbacks below are
 * the line between a sort control and an injection into `orderBy()`.
 */
class PermissionActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
    }

    public function test_the_catalogue_matches_a_bare_name_fragment(): void
    {
        $result = app(PermissionIndexAction::class)->run('roles');

        $this->assertGreaterThan(0, $result['permissions']->total());
        foreach ($result['permissions'] as $permission) {
            $this->assertStringContainsString('roles', $permission->name);
        }
    }

    public function test_an_unknown_sort_column_falls_back_instead_of_reaching_order_by(): void
    {
        $result = app(PermissionIndexAction::class)->run('', 'name; drop table users', 'asc');

        $this->assertSame('name', $result['currentSort']);
    }

    public function test_an_unknown_direction_falls_back_to_ascending(): void
    {
        $result = app(PermissionIndexAction::class)->run('', 'name', 'sideways');

        $this->assertSame('asc', $result['currentDirection']);
    }
}
