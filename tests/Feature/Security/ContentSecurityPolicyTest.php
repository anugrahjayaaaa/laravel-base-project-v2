<?php

namespace Tests\Feature\Security;

use App\Jobs\PasswordExpirySweep;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\SystemRole;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use DOMDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Storvia\Vantage\Models\VantageJob;
use Tests\TestCase;

/**
 * The Content-Security-Policy as it actually reaches the browser (pentest R3).
 *
 * ## Asserted on the rendered response, not on the middleware
 *
 * A policy is only worth what its pages do with it: the header carrying a nonce
 * proves nothing if one inline `<script>` was written without `@nonce`, because
 * the browser then silently refuses to run it — a page that is 200 and half dead.
 * So the probes read the HTML the header governs and match the two together.
 *
 * ## Every inline handler, not one of them
 *
 * `script-src` without `'unsafe-inline'` does not run `onclick=…` at all, so a
 * handler that survives a conversion is a control that stopped working rather
 * than a hole that opened. Both directions are asserted: no handler survives, and
 * the sort controls on the users table are still reachable links.
 */
class ContentSecurityPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * A `vantage_jobs` row for the two pages that need one. Written by hand, not
     * with the package factory: `VantageJobFactory` still assigns the pre-rename
     * columns (`job_name`, `memory_usage`) and dies against the migrated table.
     */
    private function vantageJob(): VantageJob
    {
        return VantageJob::create([
            'uuid' => (string) Str::uuid(),
            'job_class' => PasswordExpirySweep::class,
            'status' => 'failed',
            'queue' => 'default',
        ]);
    }

    /**
     * The policy on a response — a response with no header fails here rather than
     * in whichever assertion needed it.
     */
    private function policyOf(TestResponse $response): string
    {
        // OK first: a 500's error page is `text/html`, so it carries a policy too,
        // and asserting the header alone would be green on a broken application.
        $response->assertOk();
        $response->assertHeader('Content-Security-Policy');

        return (string) $response->headers->get('Content-Security-Policy');
    }

    /**
     * One directive's own value, so an assertion about `script-src` cannot be
     * satisfied by some other directive that happens to carry the substring.
     *
     * @return array<string, string>
     */
    private function directives(string $policy): array
    {
        $directives = [];

        foreach (explode('; ', $policy) as $pair) {
            [$name, $value] = array_pad(explode(' ', $pair, 2), 2, '');
            $directives[$name] = $value;
        }

        return $directives;
    }

    private function nonceOf(string $policy): string
    {
        preg_match("/'nonce-([^']+)'/", $policy, $matches);

        $this->assertNotSame([], $matches, 'the policy carries no nonce to match a script against');

        return $matches[1];
    }

    /**
     * The same event set the views actually used inline. A pattern wide enough to
     * catch `\son[a-z]+=` would trip on prose and on `data-on-change=`, and a probe
     * that fails for the wrong reason teaches people to ignore it.
     */
    private function assertNoInlineHandler(TestResponse $response, string $uri): void
    {
        $response->assertOk();

        $this->assertDoesNotMatchRegularExpression(
            '/\son(?:click|submit|load|error|change|input|focus|blur|keyup|keydown|mouseover|dblclick)\s*=/i',
            (string) $response->getContent(),
            "an inline event handler survived on {$uri} — a strict script-src never runs it, so the control is dead",
        );
    }

    /**
     * The policy itself: nonce in, `unsafe-*` out, and the directives that close
     * the other three exfiltration routes.
     */
    public function test_a_page_is_served_with_a_nonce_and_no_unsafe_inline(): void
    {
        $directives = $this->directives($this->policyOf($this->get('/login')));

        $script = $directives['script-src'] ?? '';

        $this->assertStringContainsString("'self'", $script);
        $this->assertStringContainsString("'nonce-", $script);
        // Redundant beside a nonce, and the redundancy is the whole hole: one
        // injected `<script>` then runs, which is what the nonce is here to stop.
        $this->assertStringNotContainsString("'unsafe-inline'", $script);
        $this->assertStringNotContainsString("'unsafe-eval'", $script);

        $this->assertSame("'none'", $directives['object-src'] ?? '', 'object-src is the plugin route');
        $this->assertSame("'none'", $directives['frame-ancestors'] ?? '', 'frame-ancestors is what X-Frame-Options used to answer');
        $this->assertSame("'self'", $directives['base-uri'] ?? '', 'base-uri is the <base> tag route');
        $this->assertSame("'self'", $directives['form-action'] ?? '', 'form-action is where a form can post to');
    }

    /**
     * Every inline block on the page carries the nonce from the header.
     *
     * The two are asserted together because either one alone is meaningless: a
     * nonce nobody prints blocks the page, and a nonce printed for a script the
     * policy does not carry blocks nothing.
     */
    public function test_every_inline_script_carries_the_policys_nonce(): void
    {
        $response = $this->get('/login');
        $nonce = $this->nonceOf($this->policyOf($response));

        preg_match_all('/<script\b([^>]*)>/i', (string) $response->getContent(), $tags);

        $inline = 0;

        foreach ($tags[1] as $attributes) {
            // An external script is allowed by `script-src 'self'` and has no nonce
            // to carry; only an inline block is bound to it.
            if (preg_match('/\bsrc=/i', $attributes) === 1) {
                continue;
            }

            $inline++;

            $this->assertMatchesRegularExpression(
                '/\bnonce="'.preg_quote($nonce, '/').'"/',
                "<script{$attributes}>",
                'an inline script on the page is not the one the policy nonced — the browser will refuse to run it',
            );
        }

        $this->assertGreaterThan(0, $inline, 'the page carries no inline script, so this probe proves nothing');
    }

    /**
     * No inline handler survives on either layout, guest or authenticated.
     */
    public function test_no_inline_event_handler_survives(): void
    {
        // `/register` answers 404 while self-registration is off — `registration_enabled`
        // ships false (`SystemSettingSeeder`) and a disabled feature 404s by design —
        // so the page has to be reachable before it can be inspected.
        SystemSetting::set('registration_enabled', 'true');

        foreach (['/login', '/register', '/forgot-password'] as $uri) {
            $this->assertNoInlineHandler($this->get($uri), $uri);
        }

        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->assignRole(SystemRole::SUPERADMIN);

        $this->actingAs($admin, 'web');

        // `/vantage/failed` and `/vantage/jobs/{id}` render two vendor views the
        // app does NOT override (`failed`, `show`): they carry no script of their
        // own, so the shared nonced layout is what keeps them working — which is
        // exactly the assumption this pair of URIs pins.
        $job = $this->vantageJob();

        foreach (['/dashboard', route('users.index'), '/vantage', '/vantage/jobs', '/vantage/tags', '/vantage/failed', route('vantage.jobs.show', $job->id)] as $uri) {
            $this->assertNoInlineHandler($this->get($uri), $uri);
        }
    }

    /**
     * The users table's sort controls are navigation, and navigation is a link.
     *
     * They were `onclick="window.location.href=…"` on a `<th>` — which a keyboard
     * user could not reach at all. Turning them into anchors is the conversion;
     * this is what proves the control did not disappear with the handler.
     */
    public function test_the_users_sort_headers_are_reachable_links(): void
    {
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->assignRole(SystemRole::SUPERADMIN);

        $this->actingAs($admin, 'web');

        $html = (string) $this->get(route('users.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<a href="[^"]*\bsort=name\b[^"]*"/', $html, 'the Name header is no longer a link');
        $this->assertMatchesRegularExpression('/<a href="[^"]*\bsort=email\b[^"]*"/', $html, 'the Email header is no longer a link');
        $this->assertDoesNotMatchRegularExpression('/\sonclick\s*=/i', $html);
    }

    /**
     * The policy is a web-page answer. A JSON response and a streamed file carry
     * no document for it to govern, and a header on the file response is the
     * classic way to corrupt the stream.
     */
    public function test_the_api_carries_no_policy(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertHeaderMissing('Content-Security-Policy');
    }

    /**
     * `npm run dev` is allowed in, and only while its hot file exists.
     *
     * The origin would otherwise be blocked by `script-src 'self'`, leaving a
     * developer with a 200 page whose JavaScript never loads — and the second half
     * is what keeps the allowance out of production, where there is no hot file.
     */
    public function test_the_vite_dev_server_origin_is_allowed_only_while_it_is_running(): void
    {
        $hot = public_path('hot');
        $created = false;

        if (! is_file($hot)) {
            file_put_contents($hot, 'http://localhost:5173');
            $created = true;
        }

        try {
            $policy = $this->policyOf($this->get('/login'));

            $this->assertStringContainsString('http://localhost:5173', $policy, 'the dev server origin is blocked');
            // HMR is a websocket: `http://` in connect-src does not match `ws://`.
            $this->assertStringContainsString('ws://localhost:5173', $policy, 'the HMR socket is blocked');
        } finally {
            if ($created) {
                unlink($hot);
            }
        }

        if (! $created) {
            // A real dev server owns the file; removing it would stop their build.
            return;
        }

        $this->assertStringNotContainsString(
            'localhost:5173',
            $this->policyOf($this->get('/login')),
            'the allowance outlived the hot file it was conditional on',
        );
    }

    /**
     * `'unsafe-eval'` exists on exactly the two vendor dashboards: `/pulse` and
     * `/vantage`.
     *
     * Pulse inlines Livewire's normal build, whose Alpine evaluates every
     * `x-data`/`wire:*` expression with `new Function` — blocked without it, so
     * every `#[Lazy]` card stays on its skeleton forever. Vantage's layout loads
     * Tailwind's Play CDN, which compiles utility classes the same way. The other
     * half of the assertion is the one that matters for safety: the relaxation is
     * scoped by route name, so an XSS hole is not being opened application-wide to
     * make two vendor pages render.
     */
    public function test_only_the_vendor_dashboards_grant_unsafe_eval(): void
    {
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->assignRole(SystemRole::SUPERADMIN);

        $this->actingAs($admin, 'web');

        $pulse = $this->directives($this->policyOf($this->get('/pulse')));
        $vantage = $this->directives($this->policyOf($this->get('/vantage')));
        $dashboard = $this->directives($this->policyOf($this->get('/dashboard')));
        $login = $this->directives($this->policyOf($this->get('/login')));

        $this->assertStringContainsString("'unsafe-eval'", $pulse['script-src'] ?? '', 'Pulse cannot evaluate its Alpine expressions without it');
        // The nonce stays even there: `unsafe-eval` is not `unsafe-inline`, and a
        // raw injected `<script>` must keep refusing to run on /pulse too.
        $this->assertStringContainsString("'nonce-", $pulse['script-src'] ?? '');
        $this->assertStringNotContainsString("'unsafe-inline'", $pulse['script-src'] ?? '');

        $this->assertStringContainsString("'unsafe-eval'", $vantage['script-src'] ?? '', "Vantage's Tailwind Play CDN needs it");
        $this->assertStringContainsString("'nonce-", $vantage['script-src'] ?? '');
        $this->assertStringNotContainsString("'unsafe-inline'", $vantage['script-src'] ?? '');

        $this->assertStringNotContainsString("'unsafe-eval'", $dashboard['script-src'] ?? '', 'the eval allowance leaked past the vendor routes');
        $this->assertStringNotContainsString("'unsafe-eval'", $login['script-src'] ?? '', 'the eval allowance leaked past the vendor routes');
    }

    /**
     * Vantage's two extra script origins travel only with the `vantage.*` route.
     *
     * The vendor layout pulls Tailwind's Play CDN and Chart.js from
     * `cdn.jsdelivr.net`; both are named origins, and both must be absent from
     * every other page — an allowlist that leaked would be a second source an XSS
     * payload could load from.
     */
    public function test_the_vantage_cdn_origins_are_scoped_to_the_vantage_route(): void
    {
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->assignRole(SystemRole::SUPERADMIN);

        $this->actingAs($admin, 'web');

        $vantage = $this->directives($this->policyOf($this->get('/vantage')));

        $this->assertStringContainsString('https://cdn.tailwindcss.com', $vantage['script-src'] ?? '');
        $this->assertStringContainsString('https://cdn.jsdelivr.net', $vantage['script-src'] ?? '');
        // Lucide's source map is a `connect-src` fetch; it is the only reason
        // unpkg needs to be in Vantage's connect-src.
        $this->assertStringContainsString('https://unpkg.com', $vantage['connect-src'] ?? '');

        foreach (['/dashboard', '/login'] as $uri) {
            $other = $this->directives($this->policyOf($this->get($uri)));

            $this->assertStringNotContainsString('https://cdn.tailwindcss.com', $other['script-src'] ?? '', "the Tailwind CDN leaked onto {$uri}");
            $this->assertStringNotContainsString('https://cdn.jsdelivr.net', $other['script-src'] ?? '', "the jsDelivr CDN leaked onto {$uri}");
            $this->assertStringNotContainsString('https://unpkg.com', $other['connect-src'] ?? '', "the unpkg connect allowance leaked onto {$uri}");
        }
    }

    /**
     * Every `<script>` element on /pulse carries the policy's nonce.
     *
     * Counted through the DOM, not with a regex over the raw HTML: /pulse inlines
     * two ~800 KB bundles, and those bundles keep their own `<script>` text
     * (Sfdump debug code), which a regex counts as tags that can never carry a
     * nonce — a probe that then fails for the wrong reason. A browser parses
     * script content as raw text; so does this.
     */
    public function test_every_script_on_the_pulse_page_carries_the_policys_nonce(): void
    {
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->assignRole(SystemRole::SUPERADMIN);

        $this->actingAs($admin, 'web');

        $response = $this->get('/pulse');
        $nonce = $this->nonceOf($this->policyOf($response));

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML((string) $response->getContent());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $inline = 0;

        foreach ($document->getElementsByTagName('script') as $script) {
            if ($script->getAttribute('src') !== '') {
                continue;
            }

            $inline++;

            $this->assertSame(
                $nonce,
                $script->getAttribute('nonce'),
                'a script on /pulse is not the one the policy nonced — the browser refuses to run it and the page stays half dead',
            );
        }

        $this->assertGreaterThan(0, $inline, 'the Pulse page carries no inline script, so this probe proves nothing');
    }

    /**
     * Every inline `<script>` on the Vantage pages carries the policy's nonce.
     *
     * The vendor layouts write their scripts without one, so the app overrides
     * four of them (`resources/views/vendor/vantage/`); this is the probe that
     * fails if an upgrade drifts one back, or if a page the app does not override
     * (`/vantage/failed`, `/vantage/jobs/{id}`) grows a script of its own. Read
     * through the DOM for the same reason the Pulse probe is: the chart script
     * inlines a JSON payload, and a regex over raw HTML is the wrong tool for
     * script content.
     */
    public function test_every_script_on_the_vantage_pages_carries_the_policys_nonce(): void
    {
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->assignRole(SystemRole::SUPERADMIN);

        $this->actingAs($admin, 'web');

        $job = $this->vantageJob();

        foreach (['/vantage', '/vantage/jobs', '/vantage/tags', '/vantage/failed', route('vantage.jobs.show', $job->id)] as $uri) {
            $response = $this->get($uri);
            $nonce = $this->nonceOf($this->policyOf($response));

            $document = new DOMDocument();
            $previous = libxml_use_internal_errors(true);
            $document->loadHTML((string) $response->getContent());
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            $inline = 0;

            foreach ($document->getElementsByTagName('script') as $script) {
                if ($script->getAttribute('src') !== '') {
                    continue;
                }

                $inline++;

                $this->assertSame(
                    $nonce,
                    $script->getAttribute('nonce'),
                    "a script on {$uri} is not the one the policy nonced — the browser refuses to run it and the page stays half dead",
                );
            }

            $this->assertGreaterThan(0, $inline, "{$uri} carries no inline script, so this probe proves nothing");
        }
    }
}
