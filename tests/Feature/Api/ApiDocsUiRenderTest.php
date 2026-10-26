<?php

namespace Tests\Feature\Api;

use App\Actions\V1\System\SystemSettingsUpdateAction;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\SystemRole;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The API documentation interface at `/docs/api`.
 *
 * Authorization is one gate — `viewApiDocs`, defined in `AuthServiceProvider` —
 * and two inputs decide it: the `api_docs_public` system setting, or the
 * `api_docs.view` permission on the signed-in user. Scramble's own
 * `RestrictedDocsAccess` is NOT in the stack: it bypasses everything in `local`,
 * which would make the setting untestable and unenforceable for a local install.
 *
 * Cases here, in the order the middleware decides them: public mode, a
 * permission holder, a superadmin (through `Gate::before`, no permission row), a
 * signed-in user who holds neither, and a guest — who is redirected to the login
 * page but gets a 401 when asking for the document itself.
 */
class ApiDocsUiRenderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);

        // The seeder writes the pivot directly, so Spatie's cached set is stale.
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_an_admin_opens_the_docs_ui_and_the_spec(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(SystemRole::ADMIN);

        $this->actingAs($admin)
            ->get('/docs/api')
            ->assertOk()
            // From config, not a literal: the title follows APP_NAME, and a
            // hardcoded string would fail the day a project renames itself.
            ->assertSee(config('scramble.ui.title'), false);

        $this->actingAs($admin)
            ->get('/docs/api.json')
            ->assertOk()
            ->assertHeader('content-type', 'application/json');
    }

    public function test_the_spec_publishes_the_version_paths_and_bearer_scheme(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(SystemRole::ADMIN);

        $response = $this->actingAs($admin)->get('/docs/api.json')->assertOk();

        $response->assertJsonPath('info.version', 'v1');
        $response->assertJsonPath('info.title', config('scramble.ui.title'));

        $spec = $response->json();

        $this->assertArrayHasKey('openapi', $spec);
        $this->assertNotEmpty($spec['paths'], 'The spec documents no paths at all.');

        // One real route, by key rather than by full URL: Scramble strips the
        // documented `api` prefix, so a v1 path is `/v1/users` served from
        // `/api/v1/users`.
        $this->assertArrayHasKey('/v1/users', $spec['paths']);

        // The Authorize box in `/docs/api` renders only when the document names
        // a scheme; Scramble derives it from the `auth:*` middleware, and a route
        // without it is marked public rather than asking for a token it ignores.
        $this->assertSame('bearer', $spec['components']['securitySchemes']['bearerAuth']['scheme']);
        $this->assertSame([['bearerAuth' => []]], $spec['security']);
        $this->assertSame([], $spec['paths']['/v1/auth/login']['post']['security']);
    }

    public function test_a_superadmin_passes_without_holding_the_permission_row(): void
    {
        $superadmin = User::factory()->create();
        $superadmin->assignRole(SystemRole::SUPERADMIN);

        $this->actingAs($superadmin)->get('/docs/api')->assertOk();
    }

    public function test_an_authenticated_user_without_the_permission_is_refused(): void
    {
        $this->assertNotSame(
            'local',
            app()->environment(),
            'Precondition: in `local` Scramble would bypass the gate, so this case would not test it.'
        );

        $user = User::factory()->create();
        $user->assignRole(SystemRole::USER);

        $this->actingAs($user)->get('/docs/api')->assertForbidden();
        $this->actingAs($user)->get('/docs/api.json')->assertForbidden();
    }

    public function test_a_guest_is_sent_to_the_login_page_when_the_docs_are_private(): void
    {
        $this->get('/docs/api')->assertRedirect(route('login'));
    }

    public function test_a_guest_asking_for_the_document_gets_a_401_not_a_login_page(): void
    {
        // The specification is machine-readable: a redirect to an HTML login
        // form tells a client nothing it can act on.
        $this->get('/docs/api.json')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'UNAUTHENTICATED')
            ->assertJsonStructure(['message', 'code', 'meta' => ['request_id', 'timestamp']]);
    }

    public function test_public_mode_opens_both_surfaces_without_a_session(): void
    {
        SystemSetting::set('api_docs_public', 'true');

        $this->get('/docs/api')->assertOk();
        $this->get('/docs/api.json')->assertOk();
    }

    public function test_the_settings_toggle_is_what_publishes_the_docs(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(SystemRole::ADMIN);

        $this->get('/docs/api')->assertRedirect(route('login'));

        app(SystemSettingsUpdateAction::class)->run(['api_docs_public' => true], causer: $admin);

        $this->assertTrue(
            SystemSetting::getBool('api_docs_public', false),
            'the toggle is validated and rendered; without the whitelist entry it saves and changes nothing.'
        );
        $this->get('/docs/api')->assertOk();

        // Off again: the same switch closes it. An unchecked checkbox posts 0,
        // and the action has to read that as false rather than "absent".
        app(SystemSettingsUpdateAction::class)->run(['api_docs_public' => false], causer: $admin);
        $this->get('/docs/api')->assertRedirect(route('login'));
    }

    public function test_the_sidebar_agrees_with_the_route(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(SystemRole::ADMIN);
        $this->actingAs($admin)->get('/dashboard')->assertSee('API Documentation');

        $user = User::factory()->create();
        $user->assignRole(SystemRole::USER);
        $this->actingAs($user)->get('/dashboard')->assertDontSee('API Documentation');

        // Public mode is a visibility change, so the menu has to follow it:
        // hiding a link to a page anyone can open is the same disagreement as
        // showing a link that 403s, with the sign flipped.
        SystemSetting::set('api_docs_public', 'true');
        $this->actingAs(User::factory()->create()->assignRole(SystemRole::USER))
            ->get('/dashboard')
            ->assertSee('API Documentation');
    }
}
