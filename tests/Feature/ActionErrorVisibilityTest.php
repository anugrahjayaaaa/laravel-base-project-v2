<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\Role;
use App\Models\RoleLookup;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A refused button-driven action must be VISIBLE.
 *
 * The reported symptom was "the confirm modal opens, I click delete, nothing is
 * deleted and nothing at all happens". The server was working the whole time: it
 * redirected back with the refusal in the error bag, and both index views
 * rendered neither the bag nor a field for it — so the browser landed on a page
 * byte-identical to the one it left. A validation error on a form is visible
 * because something renders @error next to the input; a button has no input, and
 * nothing rendered the message.
 *
 * Asserted against the page the redirect actually points at, in the same
 * session. Reading the error bag off the POST response proves nothing — the bag
 * is correct while the page that should show it renders nothing, which is the
 * entire bug.
 */
class ActionErrorVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // VerifyCsrfToken::runningUnitTests() is hardcoded false, so Laravel's
        // test-time CSRF skip never applies and every POST comes back 419.
        $this->withoutMiddleware(VerifyCsrfToken::class);

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);

        $this->admin = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
        $this->admin->assignRole(RoleLookup::find('admin'));
    }

    public function test_deleting_a_populated_role_from_the_ui_actually_works_and_says_so(): void
    {
        // The reported symptom, end to end. The modal's confirm is the deliberate
        // override, so the web path forces: clicking Delete on a role that people
        // hold must TRASH it and say so. Before both fixes this was a dead end —
        // the action refused (correctly), the controller never forced, and the
        // index rendered the refusal nowhere, so the click did nothing at all.
        $role = Role::create(['name' => 'Support Agent', 'guard_name' => RoleLookup::guard()]);
        $holder = User::factory()->create();
        $holder->assignRole($role);

        $this->actingAs($this->admin);

        // Exactly what the confirm modal's form sends: the action URL with a
        // _method spoof, not a clean DELETE.
        $post = $this->from(route('roles.index'))
            ->post(route('roles.destroy', $role), ['_method' => 'DELETE']);

        $post->assertRedirect(route('roles.index'));

        // The role is gone from every lookup that gates access, and trashed
        // rather than destroyed.
        $this->assertNull(RoleLookup::find('Support Agent'), 'the role must stop resolving');
        $this->assertTrue(
            Role::withTrashed()->where('name', 'Support Agent')->first()?->trashed(),
            'it must be in the trash, not destroyed'
        );
        $this->assertFalse($holder->fresh()->hasRole('Support Agent'), 'the holder lost the role');

        $post->assertSessionHas('status');
        $page = $this->get(route('roles.index'));
        $page->assertOk();
        $this->assertStringContainsString('moved to trash', $page->getContent());
        // A successful action must not also print a danger alert. Match the
        // partial's OWN class triple: a bare 'alert-danger' is in the confirm
        // modal's markup, which is on every page of this app.
        $this->assertSame(0, $this->dangerAlerts($page->getContent()));
    }

    /**
     * Count the danger alerts this app's partial renders.
     *
     * A bare 'alert-danger' substring is useless here: the confirm modal's markup
     * contains one and is included in the layout of every single page, so the
     * count is 1 even when the user sees nothing. The partial's exact class
     * string is what actually appears on screen.
     */
    private function dangerAlerts(string $html): int
    {
        return substr_count($html, 'alert alert-danger alert-dismissible fade show mb-3');
    }

    public function test_a_refused_user_delete_is_visible_too(): void
    {
        // Same hole, same fix. DeleteUserAction refuses self-deletion with an
        // 'email' error, which the users index also had nowhere to render — so a
        // fix scoped to the roles page would have left this identical symptom
        // one route over.
        $this->actingAs($this->admin);

        $post = $this->from(route('users.index'))->delete(route('users.destroy', $this->admin));
        $page = $this->get($post->headers->get('Location'));

        $page->assertOk();
        $this->assertStringContainsString('cannot delete your own account', $page->getContent());
        $this->assertSame(1, $this->dangerAlerts($page->getContent()));
    }

    public function test_a_successful_action_shows_no_error_alert(): void
    {
        // Covered by the populated-role test above (it asserts the same 0), but
        // stated for the UNPOPULATED case too: the unheld role is the one that
        // has no error to render, so a stray alert there would come from the
        // partial firing on an empty bag.
        $role = Role::create(['name' => 'Support Agent', 'guard_name' => RoleLookup::guard()]);

        $this->actingAs($this->admin);
        $post = $this->from(route('roles.index'))->delete(route('roles.destroy', $role));
        $page = $this->get($post->headers->get('Location'));

        $page->assertOk();
        $this->assertSame(0, $this->dangerAlerts($page->getContent()));
        $this->assertStringContainsString('moved to trash', $page->getContent());
    }

    public function test_a_form_page_does_not_print_its_validation_error_twice(): void
    {
        // The alert is included by the INDEX views, not the layout, precisely so
        // this stays true: a form page already renders @error next to the input,
        // and a layout-level banner would print the same sentence again.
        $this->actingAs($this->admin);

        $post = $this->from(route('roles.create'))->post(route('roles.store'), ['name' => 'admin']);
        $post->assertRedirect(route('roles.create'));

        $page = $this->get($post->headers->get('Location'));
        $page->assertOk();

        // Once. The form page has its OWN banner ("Please fix the errors
        // below."), which predates the partial and is what the count of 1 is —
        // not the partial, which the form correctly does not include. The check
        // that matters is the one below: the partial's message text must not
        // appear, or the same validation error prints twice.
        $this->assertSame(
            1,
            substr_count($page->getContent(), 'already been taken'),
            'the store error is rendered once, by the form, not twice'
        );
        // The form banner carries this exact class triple too, so a substring
        // check cannot separate the two. Assert the partial's own text is absent.
        $this->assertStringNotContainsString(
            'exclamation-triangle',
            $page->getContent(),
            'the index-only alert partial rendered on a form page, duplicating the field error'
        );
    }
}
