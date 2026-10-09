<?php

namespace Tests\Feature\Audit;

use App\Models\Activity;
use App\Models\RoleLookup;
use App\Models\User;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use App\Http\Middleware\VerifyCsrfToken;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Nothing in the application can create, update or delete an audit row
 * (Phase 10, P10-D7).
 *
 * `docs/base/features/audit-trail.md:19` and DEP-003:73 — the table is
 * append-only. That is currently true by the ABSENCE of anything, which is the
 * weakest kind of true: it holds until someone adds a route, and the person who
 * adds it is not reading this test. This is that test.
 *
 * The requirement most likely to be eroded is not malicious — it is a future
 * "let admins annotate an audit row" ticket. Every piece below is a structural
 * pin rather than a behavioural one, because the behaviours (the viewer does not
 * write) are already covered by the render gate.
 */
class AuditReadOnlyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // CSRF is disabled because this project enforces it inside feature tests.
        // Left on, a POST/DELETE to a genuinely UNREGISTERED method answers 419
        // — the token check runs before the method match — so the test would pass
        // for the wrong reason, and adding a real write route would leave it
        // green. The claim under test is about routing, not about tokens.
        $this->withoutMiddleware(VerifyCsrfToken::class);

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * No route anywhere accepts a write verb against the audit surface.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    public static function writeRouteProvider(): array
    {
        return [
            'post index' => ['POST', '/activity-logs'],
            'put index' => ['PUT', '/activity-logs'],
            'patch index' => ['PATCH', '/activity-logs'],
            'delete index' => ['DELETE', '/activity-logs'],
            'post show' => ['POST', '/activity-logs/1'],
            'put show' => ['PUT', '/activity-logs/1'],
            'patch show' => ['PATCH', '/activity-logs/1'],
            'delete show' => ['DELETE', '/activity-logs/1'],
        ];
    }

    #[Test]
    #[DataProvider('writeRouteProvider')]
    public function test_no_write_route_reaches_the_audit_surface(string $method, string $uri): void
    {
        // Not "assertFalse" on a matching route: a request to an unregistered
        // method is a 405, which is the answer we want. Asserting the ABSENCE of
        // a route is the claim; the status proves the router agrees.
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->assignRole(RoleLookup::find('admin'));

        $this->actingAs($admin, 'web')
            ->call($method, $uri)
            ->assertStatus(405);
    }

    #[Test]
    public function test_the_audit_routes_are_read_only_verbs(): void
    {
        foreach (['activity-logs.index', 'activity-logs.show'] as $name) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, $name.' is not registered');
            $this->assertContains(
                $route->methods()[0],
                ['GET', 'HEAD'],
                $name.' answers '.$route->methods()[0]
            );
        }
    }

    #[Test]
    public function test_the_read_model_refuses_mass_assignment(): void
    {
        // The structural half. A `$fillable` or an open `$guarded` would let an
        // `Activity::create($request->validated())` rewrite the record of
        // something that happened — and in a diff it would look like any other
        // create.
        $this->assertSame(['*'], (new Activity())->getGuarded());

        $this->expectException(MassAssignmentException::class);

        (new Activity)->fill(['description' => 'rewritten', 'event' => 'rewritten']);
    }

    #[Test]
    public function test_nothing_in_the_application_writes_through_the_activity_model(): void
    {
        // Stated honestly, because the alternative is a test that lies.
        //
        // `$guarded = ['*']` blocks `fill()` and `create()`. It does NOT block
        // `$row->description = 'x'; $row->save();` on a retrieved instance, nor
        // `Activity::query()->delete()`. Eloquent has no immutable-model switch,
        // so the model cannot be the boundary on its own — the ROUTE is, which is
        // what the 405 test above pins.
        //
        // So the remaining guard is that nothing calls the other doors. Scanned
        // rather than asserted per-call-site because a call in a service would
        // otherwise never be exercised by a route test.
        $offenders = [];

        foreach (glob(app_path('**/*.php')) as $file) {
            $source = file_get_contents($file);

            // The write path, named three ways. A false positive costs a comment
            // in the scan; a false negative costs a silent audit rewrite.
            if (preg_match('/Activity::\w+\([^)]*\)\s*->\s*(delete|update|forceDelete|truncate)\s*\(/', $source)
                || preg_match('/activity\(\)\s*->\s*(delete|update)\s*\(/', $source)
                || preg_match('/\bActivity\b[^;\n]*->\s*(delete|update|forceDelete)\s*\(/', $source)) {
                $offenders[] = str_replace(base_path().'/', '', $file);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'these call sites write through the audit read model: '.implode(', ', $offenders)
        );
    }

    #[Test]
    public function test_no_controller_receives_an_activity_request(): void
    {
        // A write endpoint needs a FormRequest or a validated array to write from.
        // No request in the project may accept an activity payload, because that
        // is the shape a future "annotate this row" endpoint would take.
        $offenders = [];

        foreach (glob(app_path('Http/Requests/**/*.php')) as $file) {
            $source = file_get_contents($file);

            if (str_contains($source, "'activity'") || str_contains($source, '"activity"')) {
                $offenders[] = $file;
            }
        }

        $this->assertSame([], $offenders, 'these requests accept an activity payload: '.implode(', ', $offenders));
    }
}
