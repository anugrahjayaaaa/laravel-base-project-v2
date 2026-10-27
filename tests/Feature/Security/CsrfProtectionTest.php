<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use ReflectionClass;
use Tests\TestCase;

/**
 * CSRF as the browser and the API actually meet it.
 *
 * `SecurityAuditTest` has one smoke probe; this file pins the whole group-B
 * contract: every mutating verb is refused without a token, a form submission
 * gets a page while a JSON caller gets the envelope, every state-changing form
 * ships a token, and the middleware cannot be silently excluded.
 */
class CsrfProtectionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * One route per verb. The CSRF check runs in the `web` group, ahead of the
     * route's own `can:`/`auth` middleware, so a refusal here is CSRF's answer
     * and not an authorization one wearing the same status.
     */
    public function test_every_state_changing_verb_is_refused_without_a_token(): void
    {
        $user = User::factory()->create();

        $requests = [
            'post' => route('login'),
            'put' => route('users.update', $user),
            'patch' => route('users.update', $user),
            'delete' => route('users.destroy', $user),
        ];

        foreach ($requests as $verb => $uri) {
            $this->{$verb}($uri)->assertStatus(419);
        }
    }

    /**
     * With the token, the request reaches its action: a failed login redirects
     * back rather than answering 419, which is the action's own status.
     */
    public function test_a_form_with_a_valid_token_reaches_its_action(): void
    {
        $this->get('/login')->assertOk();

        $this->post('/login', [
            '_token' => session()->token(),
            'email' => 'nobody@example.com',
            'password' => 'wrong-password-on-purpose',
        ])->assertRedirect();
    }

    public function test_a_json_caller_gets_the_machine_readable_419(): void
    {
        $this->postJson(route('settings.update'), [])
            ->assertStatus(419)
            ->assertJsonPath('code', 'CSRF_TOKEN_MISMATCH')
            ->assertJsonStructure(['message', 'code', 'meta' => ['request_id', 'timestamp']]);
    }

    /**
     * A browser form gets something a browser can read. The JSON envelope on an
     * HTML navigation is a 419 a user cannot act on.
     */
    public function test_a_form_submission_gets_the_419_page(): void
    {
        $response = $this->post(route('settings.update'), [])->assertStatus(419);

        $this->assertStringContainsString('text/html', (string) $response->headers->get('Content-Type'));
        $this->assertStringNotContainsString('CSRF_TOKEN_MISMATCH', (string) $response->getContent());
    }

    public function test_the_meta_tag_matches_the_session_token(): void
    {
        $content = (string) $this->get('/login')->assertOk()->getContent();

        preg_match('/<meta name="csrf-token" content="([^"]+)"/', $content, $match);

        $this->assertNotEmpty($match[1] ?? null, 'the guest layout lost its csrf-token meta tag');
        $this->assertSame(session()->token(), $match[1]);
    }

    /**
     * The app, auth and guest layouts are the three documents the application
     * renders; a script that reads the meta tag breaks on whichever one drops it.
     */
    public function test_every_layout_carries_the_meta_tag(): void
    {
        foreach (['layouts/app', 'layouts/auth', 'layouts/guest'] as $layout) {
            $this->assertStringContainsString(
                '<meta name="csrf-token" content="{{ csrf_token() }}">',
                File::get(resource_path("views/{$layout}.blade.php")),
                "{$layout} is missing the csrf-token meta tag",
            );
        }
    }

    /**
     * Every form that can mutate state, `@method`-spoofed verbs included, must
     * post a token. The shared confirmation modal is the submit surface for the
     * button-driven actions, so this scan covers them through one form.
     */
    public function test_every_state_changing_form_carries_a_token(): void
    {
        $missing = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            $html = (string) preg_replace('/\{\{--.*?--\}\}/s', '', File::get($file));

            preg_match_all('/<form\b[^>]*>/i', $html, $forms, PREG_OFFSET_CAPTURE);

            foreach ($forms[0] as [$tag, $offset]) {
                if (! preg_match('/method\s*=\s*["\']post["\']/i', $tag)) {
                    continue;
                }

                $end = strpos($html, '</form>', $offset);
                $block = substr($html, $offset, ($end === false ? strlen($html) : $end) - $offset);

                if (! str_contains($block, '@csrf')) {
                    $missing[] = $file->getRelativePathname();
                }
            }
        }

        $this->assertSame([], $missing, 'a state-changing form lost its @csrf: '.implode(', ', $missing));
    }

    /**
     * The application ships no AJAX mutation today; this fails the day one is
     * added without the token, which is the point the audit cannot be redone.
     */
    public function test_no_javascript_mutation_bypasses_the_token(): void
    {
        $unsafe = [];

        foreach ([resource_path('js'), resource_path('views')] as $root) {
            foreach (File::allFiles($root) as $file) {
                $script = File::get($file);

                $mutates = preg_match('/fetch\s*\([^)]*method\s*:\s*["\'](?:POST|PUT|PATCH|DELETE)["\']/is', $script)
                    || preg_match('/\.open\s*\(\s*["\'](?:POST|PUT|PATCH|DELETE)["\']/i', $script);

                if ($mutates && ! preg_match('/csrf|X-CSRF-TOKEN|_token/i', $script)) {
                    $unsafe[] = $file->getRelativePathname();
                }
            }
        }

        $this->assertSame([], $unsafe, 'an AJAX mutation does not send a token: '.implode(', ', $unsafe));
    }

    /**
     * Every mutating route outside `api/*` runs the group that carries the
     * check. `VerifyCsrfToken::except` stays empty so no route can opt out.
     */
    public function test_every_state_changing_web_route_runs_the_web_group(): void
    {
        $kernel = $this->app->make(Kernel::class);

        $this->assertContains(
            VerifyCsrfToken::class,
            $kernel->getMiddlewareGroups()['web'],
            'the CSRF middleware is no longer in the web group',
        );

        $mutating = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE'])
                && ! str_starts_with($route->uri(), 'api/')
                // Laravel's signed local-disk route; it is not a form, and a
                // signature — not a token — is its authorization.
                && ! str_starts_with($route->uri(), 'storage/'));

        $this->assertNotEmpty($mutating, 'the route scan matched nothing — it is measuring nothing');

        foreach ($mutating as $route) {
            $this->assertContains('web', $route->gatherMiddleware(), $route->uri().' escapes the web group');
        }

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/')) {
                $this->assertNotContains('web', $route->gatherMiddleware(), $route->uri().' picked up the web group');
            }
        }
    }

    public function test_the_csrf_exception_list_is_empty(): void
    {
        $except = new ReflectionClass(VerifyCsrfToken::class);

        $this->assertSame(
            [],
            $except->getDefaultProperties()['except'],
            'a route was exempted from CSRF; fix the caller, not the middleware',
        );
    }
}
