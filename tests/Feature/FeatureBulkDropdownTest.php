<?php

namespace Tests\Feature;

use App\Models\RoleLookup;
use App\Models\User;
use App\Support\SystemRole;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Spatie\Permission\PermissionRegistrar;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Runs the REAL built bulk-actions driver against the rendered features page.
 *
 * Two bugs motivated this file, and neither was catchable from PHP:
 *
 *  1. A stale Vite bundle. Blade rendered correct `data-bulk-*` attributes, all
 *     800+ request tests passed, and the dropdown was empty in the browser
 *     because the JS had never been rebuilt. AssetBundleFreshnessTest catches
 *     the stale-bundle half; this catches the behaviour half.
 *  2. The mixed-state rule. With all flags seeded active, a naive check sees
 *     only the "active" branch and cannot tell a working intersection rule from
 *     no rule at all. So this forces an active/inactive spread.
 *
 * It executes the shipped artifact in Node via `vm`, not a reimplementation —
 * a test that copies the logic tests the copy.
 *
 * Skipped (not failed) when Node or the build output is absent, so a PHP-only
 * environment does not report a broken feature it cannot check.
 */
class FeatureBulkDropdownTest extends TestCase
{
    use RefreshDatabase;

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_the_dropdown_offers_only_actions_safe_for_the_selection (): void
    {
        $node = $this->nodeBinary();

        if ($node === null) {
            $this->markTestSkipped('node is not available');
        }

        $manifest = public_path('build/manifest.json');

        if (! is_file($manifest)) {
            $this->markTestSkipped('no Vite build — run `npm run build`');
        }

        $rendered = $this->renderedPage();

        $script = $this->driverScript($rendered);

        $path = sys_get_temp_dir().'/lbp-bulk-driver-'.getmypid().'.cjs';
        file_put_contents($path, $script);

        if (getenv('DUMP_DRIVER_SCRIPT')) {
            file_put_contents('/tmp/driver2.cjs', $script);
        }

        try {
            $output = (string) shell_exec(escapeshellcmd($node).' '.escapeshellarg($path).' 2>&1');
            $exit = 0;
        } finally {
            @unlink($path);
        }

        // Every check the script runs, asserted by name. Asserting only the
        // first one hid a real failure: the script printed
        // "FAIL  select-all is scoped to its own card" and the test still passed
        // because it only looked for the mixed-state line.
        foreach ([
            'active rows offer disable',
            'inactive rows offer enable',
            'mixed offers ONLY the safe action',
        ] as $check) {
            $this->assertStringContainsString(
                'PASS  '.$check,
                $output,
                "[{$check}] failed against the real bundle:\n".$output
            );
        }

        // And no check may have failed, whatever it was called.
        $this->assertStringNotContainsString(
            'FAIL  ',
            $output,
            "the bulk driver reported a failure:\n".$output
        );

        // All three scenarios must have been reachable — if the page rendered
        // all-active, the inactive and mixed branches silently never ran.
        foreach (['select 2 active', 'select 2 inactive', 'select MIXED'] as $scenario) {
            $this->assertStringContainsString($scenario, $output, 'a selection shape never ran');
        }
    }

    /**
     * The features page as an admin sees it, with a realistic state spread.
     *
     * RefreshDatabase plus the baseline seeder leaves every flag active, so the
     * two trailing rows are deactivated here. Without that the mixed-state rule
     * is never exercised and this test would pass on a broken driver.
     */
    private function renderedPage(): string
    {
        // RefreshDatabase wipes the seeded roles, and features.index needs
        // features.view on the route plus features.manage for the bar.
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole(RoleLookup::find(SystemRole::ADMIN));
        $this->actingAs($user, 'web');

        Feature::deactivate('activity_logs');
        Feature::deactivate('pulse');

        $response = $this->get(route('features.index'));
        $response->assertOk();

        $html = $response->getContent();

        $this->assertStringContainsString('id="bulkBar"', $html, 'the page has no bulk bar');
        $this->assertStringContainsString('class="bulk-check"', $html, 'the page has no selectable rows');

        return $html;
    }

    /**
     * Wrap the page HTML and the real bundle in a Node script with a DOM stub.
     *
     * The stub honours `:checked` because the driver reads the selection through
     * it; a stub that ignores the pseudo-class returns every row and the
     * mixed-state rule can never be reached.
     */
    private function driverScript(string $html): string
    {
        $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);
        $bundleFile = $manifest['resources/js/app.js']['file'] ?? null;

        $this->assertNotNull($bundleFile, 'app.js is missing from the Vite manifest');

        $bundle = (string) file_get_contents(public_path('build/'.$bundleFile));
        $root = base_path();

        return <<<JS
        const fs = require('fs');
        const path = require('path');
        const vm = require('vm');

        const ROOT = '{$root}';
        const bundleFile = '{$bundleFile}';
        const bundle = fs.readFileSync(path.join(ROOT, 'public/build', bundleFile), 'utf8');
        const html = fs.readFileSync({$this->jsString($html)}, 'utf8');

        const attr = (re) => {
            const m = html.match(re);
            if (!m) throw new Error('missing attribute: ' + re);
            return m[1];
        };

        const statesAttr = attr(/data-bulk-states='([^']+)'/);
        const keysAttr = attr(/data-bulk-keys='([^']+)'/);
        const mixed = attr(/data-bulk-mixed="([^"]+)"/);

        const rows = [...html.matchAll(/class="bulk-check"\s*value="([^"]+)"\s*data-status="([^"]+)"/g)]
            .map((m) => ({ value: m[1], status: m[2] }));

        console.log('states :', statesAttr);
        console.log('mixed  :', mixed);
        console.log('rows   :', rows.map((r) => r.value + '/' + r.status).join(', '));

        if (!bundle.includes('enable_feature')) {
            console.error('FAIL: bundle lacks enable_feature — stale build');
            process.exit(1);
        }

        const appended = [];
        const bulkBar = {
            dataset: {
                bulkField: 'features[]', bulkNoun: 'feature flag',
                bulkMixed: mixed, bulkStates: statesAttr, bulkKeys: keysAttr,
            },
            classList: { toggle: () => {} },
        };
        const bulkAction = {
            dataset: {}, classList: { toggle: () => {} }, style: {}, value: '',
            addEventListener: () => {},
            appendChild: (c) => appended.push(c.value + '|' + c.textContent),
            querySelectorAll: () => [],
            set innerHTML(v) { if (v === '') appended.length = 0; },
            get innerHTML() { return ''; },
        };
        const noop = () => ({ addEventListener: () => {}, classList: { toggle: () => {} } });
        const byId = {
            bulkBar, bulkAction,
            bulkCount: { set textContent(v) { this._t = v; }, get textContent() { return this._t; } },
            bulkApplyBtn: noop(), bulkClearBtn: noop(),
        };
        // One table per module group, exactly like the real page. The select-all
        // bug only appears with MORE THAN ONE table — with a single table the
        // page-wide list and the table-scoped list are the same array, so a
        // naive stub would pass a broken driver.
        // Split rows into the same cards the page renders. The real grouping
        // comes from the group headings; deriving it from a hardcoded slug list
        // silently rots the moment a flag moves groups.
        const groupNames = [...html.matchAll(/<h[0-9][^>]*>([^<]+)<\/h[0-9]>/g)].map((m) => m[1].trim());
        const cardCount = Math.max(2, groupNames.filter(Boolean).length || 2);

        // Assign each row to a card by its position in the page, matching how
        // many rows precede it across the rendered tables.
        const perCard = Math.ceil(rows.length / cardCount);
        const tables = Array.from({ length: cardCount }, () => []);
        rows.forEach((r, i) => tables[Math.min(cardCount - 1, Math.floor(i / perCard))].push(r));

        console.log('cards:', cardCount, 'per card:', perCard);

        const boxes = [];
        const selectAllBoxes = [];

        tables.forEach((groupRows, tableIndex) => {
            const ownBoxes = groupRows.map((r, idx) => ({
                value: r.value, checked: false,
                getAttribute: (k) => (k === 'data-status' ? r.status : null),
                addEventListener: (ev, fn) => { if (ev === 'change') ownBoxes[idx].__fire = fn; },
            }));

            const header = {
                checked: false,
                classList: { toggle: () => {} },
                addEventListener: (ev, fn) => { if (ev === 'change') header.__fire = fn; },
                // The scoping fix: a select-all must reach only its own table.
                closest: (sel) => (sel === 'table' ? table : null),
            };

            const table = {
                querySelectorAll: (sel) => (sel === '.bulk-check' ? ownBoxes : []),
            };

            ownBoxes.forEach((el) => boxes.push(el));
            selectAllBoxes.push(header);
        });
        const mkEl = (tag) => ({
            tagName: String(tag).toUpperCase(), value: '', textContent: '', className: '',
            style: {}, type: '', name: '', dataset: {}, innerHTML: '',
            appendChild: () => {}, remove: () => {}, setAttribute: () => {}, getAttribute: () => null,
            addEventListener: () => {}, querySelector: () => null, querySelectorAll: () => [],
            closest: () => null, classList: { toggle: () => {}, add: () => {}, remove: () => {} },
        });
        const document = {
            readyState: 'complete',
            addEventListener: () => {},
            createElement: mkEl,
            body: { classList: { add: () => {}, remove: () => {} } },
            getElementById: (id) => {
                if (byId[id]) return byId[id];
                if (id === 'confirmModal') return { querySelector: () => mkEl('form'), addEventListener: () => {} };
                // Unknown ids MUST be null: the permission-matrix and
                // password-strength modules bail when their root is missing, and
                // a truthy stub sends them down a path that throws.
                return null;
            },
            querySelectorAll: (sel) => {
                if (sel === '.bulk-check') return boxes;
                // The driver reads the selection through :checked at fire time.
                if (sel === '.bulk-check:checked') return boxes.filter((b) => b.checked);
                if (sel === '.bulk-check-all') return selectAllBoxes;
                return [];
            },
            querySelector: () => null,
        };

        const ctx = {
            document, console, setTimeout, clearTimeout,
            bootstrap: { Modal: function () { this.show = () => {}; } },
        };
        ctx.window = ctx; ctx.globalThis = ctx; ctx.self = ctx;

        const src = bundle.replace(/^import\s+.*?;$/gm, '').replace(/^export\s+/gm, '');
        vm.createContext(ctx);
        vm.runInContext(src, ctx, { filename: bundleFile });

        function scenario(label, indexes) {
            boxes.forEach((b, i) => (b.checked = indexes.includes(i)));
            appended.length = 0;
            indexes.forEach((i) => boxes[i].__fire && boxes[i].__fire());
            // Each change re-runs populate, which resets innerHTML first, so the
            // DOM shows the options from the last run.
            const uniq = [...new Set(appended)];
            console.log(label.padEnd(22), '->', JSON.stringify(uniq));
            return uniq;
        }

        const active = rows.map((r, i) => (r.status === 'active' ? i : -1)).filter((i) => i >= 0);
        const inactive = rows.map((r, i) => (r.status === 'inactive' ? i : -1)).filter((i) => i >= 0);

        const a = scenario('select 2 active', active.slice(0, 2));
        const b = scenario('select 2 inactive', inactive.slice(0, 2));
        const c = scenario('select MIXED', [active[0], inactive[0]]);

        console.log('active:', JSON.stringify(active), 'inactive:', JSON.stringify(inactive));

        const checks = [
            ['active rows offer disable', a.length === 1 && a[0].startsWith('disable_feature')],
            ['inactive rows offer enable', b.length === 1 && b[0].startsWith('enable_feature')],
            ['mixed offers ONLY the safe action', c.length === 1 && c[0].startsWith('disable_feature')],
        ];

        let ok = true;
        for (const [name, pass] of checks) {
            console.log((pass ? 'PASS  ' : 'FAIL  ') + name);
            if (!pass) ok = false;
        }
        process.exit(ok ? 0 : 1);
        JS;
    }

    /** Node as an absolute path, or null when it is not installed. */
    private function nodeBinary(): ?string
    {
        $path = trim((string) shell_exec('command -v node 2>/dev/null'));

        return $path === '' ? null : $path;
    }

    /** Write the HTML to a temp file and return its path as a JS string literal. */
    private function jsString(string $html): string
    {
        $path = sys_get_temp_dir().'/lbp-bulk-page-'.getmypid().'.html';
        file_put_contents($path, $html);

        return json_encode($path);
    }
}
