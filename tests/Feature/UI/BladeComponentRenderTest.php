<?php

namespace Tests\Feature\UI;

use App\Models\RoleLookup;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ViewErrorBag;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Phase 15 P15-A1: the application shell and the shared UI components render,
 * and no application view is written against a light-only surface.
 *
 * The component tests render through `Blade::render()` rather than a fixture
 * page, because a component that only ever renders inside one page is a
 * component nothing pins on its own.
 */
class BladeComponentRenderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function signIn(string $role = 'admin'): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole(RoleLookup::find($role));

        $this->actingAs($user);

        return $user;
    }

    private function renderLayout(string $view): string
    {
        return view($view, ['errors' => new ViewErrorBag(), 'title' => 'Shell'])->render();
    }

    public function test_the_app_layout_renders_the_full_shell(): void
    {
        $this->signIn();
        $html = $this->renderLayout('layouts.app');

        $this->assertStringContainsString('<html lang="en">', $html);
        $this->assertStringContainsString('class="app-wrapper"', $html);
        $this->assertStringContainsString('class="app-sidebar', $html);
        $this->assertStringContainsString('class="app-header', $html);
        $this->assertStringContainsString('class="app-footer', $html);
        $this->assertStringContainsString('<main class="app-main">', $html);
        $this->assertStringContainsString('<title>Shell</title>', $html);

        // The theme is chosen before first paint, so a dark-mode user never sees
        // the light surface flash. The attribute is the whole mechanism.
        $this->assertStringContainsString("setAttribute('data-bs-theme', theme)", $html);
        $this->assertMatchesRegularExpression('/<script[^>]*nonce="[^"]*"[^>]*>/', $html);
    }

    public function test_the_app_shell_renders_the_menu_for_a_signed_in_admin(): void
    {
        $this->signIn();
        $html = $this->renderLayout('layouts.app');

        $this->assertStringContainsString('sidebar-menu', $html);
        $this->assertStringContainsString('nav-item', $html);
        $this->assertStringContainsString(route('dashboard'), $html);
    }

    public function test_the_guest_and_auth_layouts_render_their_shell(): void
    {
        // The admin shell wraps itself in `app-wrapper`; the auth shell has its
        // own centred wrapper, so the assertion is per layout rather than one
        // shared string.
        foreach (['layouts.guest' => 'app-wrapper', 'layouts.auth' => 'auth-wrapper'] as $layout => $wrapper) {
            $html = $this->renderLayout($layout);

            $this->assertStringContainsString('<html lang="en">', $html, "{$layout} has no document");
            $this->assertStringContainsString($wrapper, $html, "{$layout} has no wrapper");
            $this->assertMatchesRegularExpression('/<script[^>]*nonce="[^"]*"[^>]*>/', $html, "{$layout} has an un-nonced script");
        }
    }

    public function test_the_confirmation_modal_is_included_exactly_once(): void
    {
        $this->signIn();

        $this->assertSame(1, substr_count($this->renderLayout('layouts.app'), 'id="confirmModal"'));
    }

    public function test_a_page_supplies_the_breadcrumb_the_layout_has_no_slot_for(): void
    {
        $this->signIn();
        $html = view('pages.users.create', ['errors' => new ViewErrorBag()])->render();

        $this->assertStringContainsString('<ol class="breadcrumb', $html);
        $this->assertStringContainsString('breadcrumb-item active">Create User', $html);
        $this->assertStringContainsString(route('users.index'), $html);
    }

    public function test_the_badge_maps_every_variant_to_its_subtle_token(): void
    {
        $expected = [
            'primary' => 'bg-primary-subtle',
            'success' => 'bg-success-subtle',
            'warning' => 'bg-warning-subtle',
            'danger' => 'bg-danger-subtle',
            'info' => 'bg-info-subtle',
            'neutral' => 'bg-secondary-subtle',
        ];

        foreach ($expected as $variant => $class) {
            $html = Blade::render('<x-ui.badge variant="'.$variant.'" text="Label" />');

            $this->assertStringContainsString($class, $html, "variant {$variant} lost {$class}");
            $this->assertStringContainsString('Label', $html);
        }
    }

    public function test_the_empty_state_renders_its_message(): void
    {
        $html = Blade::render('<x-ui.empty-state message="Nothing here yet." />');

        $this->assertStringContainsString('Nothing here yet.', $html);
        $this->assertStringContainsString('ui-state-wrapper', $html);
    }

    public function test_a_sortable_header_marks_only_the_active_column(): void
    {
        $html = Blade::render(
            '<x-ui.sortable-th field="name" label="Name" current-sort="email" current-direction="asc" />'
            .'<x-ui.sortable-th field="email" label="Email" current-sort="email" current-direction="asc" />'
            .'<x-ui.sortable-th field="created_at" label="Created" />'
        );

        // `aria-sort` on the active column only: seven "none" announcements drown
        // the one cell that carries the answer.
        $this->assertSame(1, substr_count($html, 'aria-sort="ascending"'), 'the active column is not marked');
        $this->assertSame(3, substr_count($html, 'scope="col"'), 'a header cell lost its scope');
        $this->assertStringContainsString('fa-arrow-up', $html, 'the active column lost its direction icon');
    }

    public function test_the_confirmation_trigger_emits_its_variant_only_when_given(): void
    {
        $danger = Blade::render('<x-ui.confirm-action action="/x" label="Delete" variant="danger" />');
        $plain = Blade::render('<x-ui.confirm-action action="/x" label="Delete" />');

        $this->assertStringContainsString('data-variant="danger"', $danger);
        $this->assertStringNotContainsString('data-variant', $plain, 'an unset variant fabricated one');
    }

    public function test_the_feature_toggle_is_a_switch_for_a_manager_and_a_badge_for_a_viewer(): void
    {
        $feature = [
            'slug' => 'demo',
            'label' => 'Demo',
            'description' => 'A demo flag.',
            'enabled' => true,
            'toggle_url' => '/features/demo/toggle?enabled=0',
            'permissions' => ['demo.view'],
        ];

        $manager = Blade::render(
            '<x-ui.feature-toggle :feature="$feature" :manageable="true" />',
            ['feature' => $feature]
        );
        $viewer = Blade::render(
            '<x-ui.feature-toggle :feature="$feature" :manageable="false" />',
            ['feature' => $feature]
        );

        $this->assertStringContainsString('type="checkbox"', $manager);
        $this->assertStringContainsString('data-action="'.$feature['toggle_url'].'"', $manager);
        // Turning a running module off is the destructive direction: the confirm
        // modal must say so in warning, not success.
        $this->assertStringContainsString('data-variant="warning"', $manager);

        $this->assertStringNotContainsString('type="checkbox"', $viewer, 'a viewer was given a switch');
        $this->assertStringContainsString('Active', $viewer);

        // The other direction: an OFF flag offers an enable, and it carries the
        // success variant.
        $off = $feature;
        $off['enabled'] = false;

        $enable = Blade::render('<x-ui.feature-toggle :feature="$feature" :manageable="true" />', ['feature' => $off]);

        $this->assertStringContainsString('data-variant="success"', $enable);
        $this->assertStringContainsString('Enable', $enable);
    }

    public function test_no_application_view_uses_a_light_only_surface(): void
    {
        $files = Finder::create()
            ->files()
            ->in(resource_path('views'))
            ->name('*.blade.php')
            ->exclude('vendor');

        $seen = 0;

        foreach ($files as $file) {
            $seen++;
            $source = preg_replace('/\{\{--.*?--\}\}/s', '', $file->getContents());

            $this->assertDoesNotMatchRegularExpression(
                '/\bbg-white\b|\bbg-light\b/',
                $source,
                $file->getRelativePathname().' uses a light-only surface — it is a white bar in dark mode'
            );
        }

        // `vendor/` holds the Pulse and Vantage overrides, which do use bg-white
        // and are not ours to restyle. The exclusion is asserted by count so a
        // wrong resource path cannot make the sweep pass over nothing.
        $this->assertGreaterThan(40, $seen, 'the sweep read too few views to be meaningful');
    }

    public function test_every_card_footer_uses_the_theme_adaptive_background(): void
    {
        $files = Finder::create()
            ->files()
            ->in(resource_path('views'))
            ->name('*.blade.php')
            ->exclude('vendor');

        $footers = 0;

        foreach ($files as $file) {
            $source = preg_replace('/\{\{--.*?--\}\}/s', '', $file->getContents());

            foreach (preg_split('/\R/', $source) ?: [] as $line) {
                if (! str_contains($line, 'card-footer')) {
                    continue;
                }

                $footers++;

                $this->assertStringContainsString(
                    'bg-body-tertiary',
                    $line,
                    $file->getRelativePathname().' has a card-footer without the theme-adaptive background'
                );
            }
        }

        $this->assertGreaterThan(5, $footers, 'the sweep found too few footers to be meaningful');
    }
}
