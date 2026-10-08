<?php

namespace Tests\Feature\FeatureFlag;

use Tests\TestCase;

/**
 * Select-all must work on BOTH page shapes, checked against the real bundle.
 *
 * The pages differ deliberately:
 *
 *  - users / roles render ONE table, so their select-all is an id
 *    (`id="bulkSelectAll"`);
 *  - features renders ONE TABLE PER MODULE, so a single id would be a duplicate
 *    -id bug and its headers carry a class instead
 *    (`class="bulk-check-all"`), each scoped to its own card.
 *
 * Narrowing the driver to the class silently killed select-all on every other
 * page: `querySelectorAll('.bulk-check-all')` returns nothing there, so the
 * handler is never bound and the checkbox simply looks inert. Nothing in PHP
 * can see that, which is why this runs the shipped artifact.
 *
 * Each shape gets its OWN vm context and document stub. Evaluating both in one
 * context has them fight over the stub elements and produce flaky results.
 */
class FeatureSelectAllTest extends TestCase
{
    
    public function test_select_all_works_on_both_page_shapes(): void
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));

        if ($node === '') {
            $this->markTestSkipped('node is not available');
        }

        if (! is_file(public_path('build/manifest.json'))) {
            $this->markTestSkipped('no Vite build — run `npm run build`');
        }

        $path = sys_get_temp_dir().'/lbp-select-all-'.getmypid().'.cjs';
        file_put_contents($path, $this->driverScript());

        $output = (string) shell_exec(escapeshellcmd($node).' '.escapeshellarg($path).' 2>&1');

        @unlink($path);

        foreach ([
            'users/roles select-all is bound at all',
            'users/roles select-all ticks every row',
            'features: both card headers are bound',
            'features: card 1 ticks only its own rows',
            'features: card 2 ticks only its own rows',
        ] as $check) {
            $this->assertStringContainsString(
                'PASS  '.$check,
                $output,
                "[{$check}] failed against the real bundle:\n".$output
            );
        }

        $this->assertStringNotContainsString('FAIL  ', $output, $output);
    }

    /** The Node program above, with the project root interpolated. */
    private function driverScript(): string
    {
        $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);
        $bundleFile = $manifest['resources/js/app.js']['file'] ?? null;

        $this->assertNotNull($bundleFile, 'app.js is missing from the Vite manifest');

        return str_replace(
            ['__ROOT__', '__BUNDLE_FILE__'],
            [base_path(), (string) $bundleFile],
            <<<'JS'
            const fs = require('fs');
            const path = require('path');
            const vm = require('vm');

            const ROOT = '__ROOT__';
            const bundleFile = '__BUNDLE_FILE__';
            const bundle = fs.readFileSync(path.join(ROOT, 'public/build', bundleFile), 'utf8');
            const src = bundle.replace(/^import\s+.*?;$/gm, '').replace(/^export\s+/gm, '');

            const FLAGS = [
                { slug: 'users', status: 'active' },
                { slug: 'roles', status: 'active' },
                { slug: 'sessions', status: 'active' },
                { slug: 'pulse', status: 'inactive' },
                { slug: 'activity_logs', status: 'inactive' },
            ];

            const mkEl = (tag) => ({
                tagName: String(tag).toUpperCase(), value: '', textContent: '', className: '',
                style: {}, type: '', name: '', dataset: {}, innerHTML: '',
                appendChild: () => {}, remove: () => {}, setAttribute: () => {},
                getAttribute: () => null,
                addEventListener: () => {}, querySelector: () => null, querySelectorAll: () => [],
                closest: () => null, classList: { toggle: () => {}, add: () => {}, remove: () => {} },
            });

            function run(shape, headerSpec) {
                const boxes = FLAGS.map((f, i) => ({
                    value: f.slug, checked: false,
                    getAttribute: (k) => (k === 'data-status' ? f.status : null),
                    addEventListener: (ev, fn) => { if (ev === 'change') boxes[i].__fire = fn; },
                }));

                const headers = headerSpec.map((h, i) => {
                    const own = h.own.map((idx) => boxes[idx]);
                    const table = { querySelectorAll: (sel) => (sel === '.bulk-check' ? own : []) };
                    return {
                        id: h.id, checked: false,
                        classList: { toggle: () => {} },
                        addEventListener: (ev, fn) => { if (ev === 'change') headers[i].__fire = fn; },
                        // A header inside a table scopes to it; a bare one has
                        // no table ancestor, which is the users/roles layout.
                        closest: (sel) => (sel === 'table' && h.inTable ? table : null),
                    };
                });

                const bar = {
                    dataset: {
                        bulkField: 'features[]', bulkNoun: 'feature flag',
                        bulkMixed: 'disable_feature',
                        bulkStates: JSON.stringify({ active: ['disable_feature'], inactive: ['enable_feature'] }),
                        bulkKeys: JSON.stringify({ enable_feature: 'enable_feature', disable_feature: 'disable_feature' }),
                    },
                    classList: { toggle: () => {} },
                };
                const act = {
                    dataset: {}, classList: { toggle: () => {} }, style: {}, value: '',
                    addEventListener: () => {}, appendChild: () => {}, querySelectorAll: () => [],
                    set innerHTML(v) {}, get innerHTML() { return ''; },
                };
                const noop = () => ({ addEventListener: () => {}, classList: { toggle: () => {} } });
                const byId = {
                    bulkBar: bar, bulkAction: act, bulkCount: {},
                    bulkApplyBtn: noop(), bulkClearBtn: noop(),
                };

                const document = {
                    readyState: 'complete',
                    addEventListener: () => {},
                    createElement: mkEl,
                    body: { classList: { add: () => {}, remove: () => {} } },
                    getElementById: (id) => {
                        if (byId[id]) return byId[id];
                        if (id === 'confirmModal') {
                            return { querySelector: () => mkEl('form'), addEventListener: () => {} };
                        }
                        return null;
                    },
                    querySelectorAll: (sel) => {
                        if (sel === '.bulk-check') return boxes;
                        if (sel === '.bulk-check:checked') return boxes.filter((b) => b.checked);
                        if (sel === '#bulkSelectAll, .bulk-check-all') return headers;
                        if (sel === '.bulk-check-all') return headers.filter((h) => !h.id);
                        return [];
                    },
                    querySelector: () => null,
                };

                const ctx = {
                    document, console, setTimeout, clearTimeout,
                    bootstrap: { Modal: function () { this.show = () => {}; } },
                };
                ctx.window = ctx; ctx.globalThis = ctx; ctx.self = ctx;
                vm.createContext(ctx);
                vm.runInContext(src, ctx, { filename: bundleFile + '#' + shape });

                const results = headers.map((h) => {
                    boxes.forEach((b) => (b.checked = false));
                    if (typeof h.__fire !== 'function') return { bound: false, ticked: [] };
                    h.checked = true;
                    h.__fire();
                    return { bound: true, ticked: boxes.filter((b) => b.checked).map((b) => b.value) };
                });

                return { count: headers.length, results };
            }

            const checks = [];

            // users / roles: ONE table, header by id.
            const single = run('single-table', [{ id: 'bulkSelectAll', inTable: false, own: [0, 1, 2, 3, 4] }]);
            console.log('users/roles (id="bulkSelectAll"):');
            console.log('  headers matched :', single.count);
            console.log('  bound           :', single.results[0].bound);
            console.log('  ticked          :', JSON.stringify(single.results[0].ticked));

            checks.push(['users/roles select-all is bound at all',
                single.count === 1 && single.results[0].bound]);
            checks.push(['users/roles select-all ticks every row',
                single.results[0].ticked.length === FLAGS.length]);

            // features: one table per module, header by class, each scoped.
            const perCard = run('per-card', [
                { id: null, inTable: true, own: [0, 1] },
                { id: null, inTable: true, own: [2, 3, 4] },
            ]);
            console.log('features (class="bulk-check-all", one table per module):');
            console.log('  headers matched :', perCard.count);
            perCard.results.forEach((r, i) => {
                console.log('  card ' + (i + 1) + '         : bound=' + r.bound + ' ticked=' + JSON.stringify(r.ticked));
            });

            checks.push(['features: both card headers are bound',
                perCard.count === 2 && perCard.results.every((r) => r.bound)]);
            checks.push(['features: card 1 ticks only its own rows',
                JSON.stringify(perCard.results[0].ticked) === JSON.stringify(['users', 'roles'])]);
            checks.push(['features: card 2 ticks only its own rows',
                JSON.stringify(perCard.results[1].ticked)
                    === JSON.stringify(['sessions', 'pulse', 'activity_logs'])]);

            console.log('');
            let ok = true;
            for (const [name, pass] of checks) {
                console.log((pass ? 'PASS  ' : 'FAIL  ') + name);
                if (!pass) ok = false;
            }
            process.exit(ok ? 0 : 1);
            JS
        );
    }
}
