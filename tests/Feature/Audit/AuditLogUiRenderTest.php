<?php

namespace Tests\Feature\Audit;

use App\Actions\V1\Audit\AuditIndexAction;
use App\Actions\V1\Audit\AuditShowAction;
use App\Models\Activity;
use App\Models\RoleLookup;
use App\Models\User;
use App\Support\SystemRole;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Phase 10 Group A gate: the audit viewer renders, obeys the design system, and
 * executes no query of its own.
 *
 * Same two rules `SettingsUiRenderTest`, `RbacUiRenderTest` and
 * `FeatureFlagUiRenderTest` already enforce for their pages — `ui-architecture.md`
 * rule 1 (no query from inside a view) and the forbidden class list — plus the
 * one thing this page uniquely must never grow: an edit affordance.
 *
 * ## Every assertion is scoped to the page, not the whole response
 *
 * `layouts.app` contains the sidebar, the header search and the logout form. A
 * `<form>`, an `input-group` and an unlabelled input therefore EXIST in every
 * response, and an assertion on the full HTML passes or fails on the layout
 * rather than on the page under test. Everything below is measured against the
 * `<main class="app-main">` region — the page's own output — for that reason, and
 * it is the same scoping mistake the sibling render tests warn about when they
 * say "assert a view's own markup, not the page's".
 *
 * ## The query counter renders the VIEW, not a request
 *
 * `AuditIndexAction` legitimately runs five queries — the page plus four filter
 * option lists. Counting those against the view would blame the controller for
 * the template's behaviour. So the view is rendered directly with the data the
 * controller hands it, exactly as `SettingsUiRenderTest` does.
 */
class AuditLogUiRenderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->viewer = User::factory()->create([
            'email_verified_at' => now(),
            'name' => 'Ana Saktivia',
        ]);
        $this->viewer->assignRole(RoleLookup::find(SystemRole::ADMIN));
        $this->actingAs($this->viewer, 'web');

        $this->subject = User::factory()->create(['name' => 'Budi Santoso']);

        $this->lockedId = $this->record('user.locked', [
            'ip' => '10.0.0.5',
            'user_agent' => 'Mozilla/5.0',
            'source' => 'web',
            'reason' => 'manual',
        ]);

        $this->record('auth.login', [
            'ip' => '10.0.0.6',
            'user_agent' => 'Mozilla/5.0',
            'source' => 'api',
        ]);
    }

    private User $viewer;

    private User $subject;

    private int $lockedId;

    /**
     * @param  array<string, mixed>  $properties
     */
    private function record(string $event, array $properties = []): int
    {
        activity()->performedOn($this->subject)->event($event)->causedBy($this->viewer)
            ->withProperties($properties)
            ->log($event);

        return (int) DB::table(config('activitylog.table_name', 'activity_log'))->max('id');
    }

    /**
     * The page's own markup: everything between `<main class="app-main">` and its
     * closing tag. See the class docblock for why the full response will not do.
     */
    private function page(string $html): string
    {
        preg_match('#<main class="app-main">(.*?)</main>#s', $html, $m);

        return $m[1] ?? '';
    }

    /**
     * The data `ActivityLogController::index()` hands the view.
     *
     * Built here rather than by new-ing up an `ActivityQueryRequest`: a
     * `FormRequest` resolves its validator through the container, so
     * `$request->filters()` on a hand-constructed instance calls `validated()` on
     * a null validator. The `filters` array below is a literal that MIRRORS what
     * `ActivityQueryRequest::filters()` returns for an unfiltered request — same
     * keys, same types, same defaults. A fixture of a different shape would test
     * a shape the controller can never produce, which is the fixture defect the
     * settings render test documents.
     *
     * @return array<string, mixed>
     */
    private function indexData(): array
    {
        $action = app(AuditIndexAction::class);

        return [
            'activities' => $action->run([]),
            'filters' => [
                'search' => null,
                'event' => null,
                'source' => null,
                'causer_id' => null,
                'subject_type' => null,
                'date_from' => null,
                'date_to' => null,
                'sort' => 'created_at',
                'direction' => 'desc',
                'per_page' => 10,
            ],
            'hasFilters' => false,
            'eventOptions' => $action->eventOptions(),
            'sourceOptions' => $action->sourceOptions(),
            'subjectTypeOptions' => $action->subjectTypeOptions(),
            'causerOptions' => $action->causerOptions(),
        ];
    }

    /**
     * Render a page view with data ALREADY resolved.
     *
     * Kept separate from `indexData()` so the query-count test can resolve once
     * and then measure the render alone. Folding the two together would count the
     * action's five legitimate queries against the template and report a page
     * that is not at fault.
     *
     * `errors` is passed because a bare `render()` outside a request has no
     * `ShareErrorsFromSession` behind it, and `@include('…alerts')` reads
     * `$errors->any()`.
     *
     * @param  array<string, mixed>  $data
     */
    private function render(string $view, array $data): string
    {
        return $this->page(view($view, $data + ['errors' => new ViewErrorBag()])->render());
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function renderIndex(array $data = []): string
    {
        return $this->render('pages.activity-logs.index', $data + $this->indexData());
    }

    private function showData(int $id): array
    {
        return ['activity' => app(AuditShowAction::class)->run($id)];
    }

    private function renderShow(int $id): string
    {
        return $this->render('pages.activity-logs.show', $this->showData($id));
    }

    /**
     * `ui-architecture.md` rule 1: a view reads variables, it never reaches for a
     * model.
     *
     * Measured as a DELTA across a warm second render rather than an absolute
     * count, because the first render primes caches the layout's composers read —
     * the sidebar's flag snapshot, Spatie's permission cache — and counting from
     * a cold start would attribute those to the page.
     *
     * The known blind spot, stated rather than hidden: this proves the view
     * issued no UNCACHED query. A cache-backed accessor never reaches the log at
     * all, which is why the source scan below sits beside it.
     */
    #[Test]
    public function test_the_index_renders_and_queries_nothing(): void
    {
        // Resolve the data ONCE, outside the log. `AuditIndexAction` legitimately
        // runs five queries — the page plus four filter option lists — and
        // counting those against the template blames the controller for the view's
        // behaviour, which is the whole point of separating them.
        $data = $this->indexData();

        $this->render('pages.activity-logs.index', $data);

        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $this->render('pages.activity-logs.index', $data);
            $count = count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }

        $this->assertSame(
            0,
            $count,
            'the index view issued '.$count.' queries; a view must read only the variables it was handed'
        );
    }

    #[Test]
    public function test_the_show_page_renders_and_queries_nothing(): void
    {
        $data = $this->showData($this->lockedId);

        $this->render('pages.activity-logs.show', $data);

        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $this->render('pages.activity-logs.show', $data);
            $count = count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }

        $this->assertSame(0, $count, 'the detail view issued '.$count.' queries');
    }

    /**
     * The source scan beside the query counter.
     *
     * A Blade file naming a class or calling the Gate compiles fine — `view:cache`
     * stays green — and renders fine until the value it wanted was not there.
     * Neither is visible to a runtime query log.
     *
     * Enumerated with Symfony Finder, NOT `glob()`: PHP's `glob()` has no
     * recursive mode, so a double-star there is one literal path segment rather
     * than "any depth" — it reads one directory level and the assertion passes
     * while most files were never opened. The file count is asserted for that
     * reason.
     */
    #[Test]
    public function test_the_view_sources_never_call_the_gate_or_a_model(): void
    {
        $files = (new Finder())
            ->files()
            ->in(resource_path('views/pages/activity-logs'))
            ->name('*.blade.php');

        $seen = 0;
        $offenders = [];

        foreach ($files as $file) {
            $seen++;

            // Strip Blade comments FIRST: a comment documenting the ban names the
            // very call it forbids, so matching the raw source trips the scan
            // against itself.
            $source = trim(preg_replace('/\{\{--.*?--\}\}/s', '', $file->getContents()) ?? '');

            foreach ([
                'auth()->user()?->can(' => 'reads the Gate in Blade',
                'Auth::user()?->can(' => 'reads the Gate in Blade',
                'SystemSetting::' => 'reads a setting in Blade',
                'Activity::' => 'names a class in Blade',
            ] as $needle => $why) {
                if (str_contains($source, $needle)) {
                    $offenders[] = $file->getFilename().': '.$why.' ('.$needle.')';
                }
            }
        }

        $this->assertSame([], $offenders, implode('; ', $offenders));
        $this->assertGreaterThanOrEqual(
            3,
            $seen,
            'the scan read '.$seen.' files — a shallow enumerator would pass this guard vacuously'
        );
    }

    /**
     * The design system's forbidden classes, on both pages.
     *
     * `bg-white` / `bg-light` break in dark mode; `card-body` without `p-4`
     * breaks the one-card-body convention. `design-system.md` §Forbidden Classes.
     */
    #[Test]
    public function test_the_forbidden_classes_never_appear(): void
    {
        foreach (['index' => $this->renderIndex(), 'show' => $this->renderShow($this->lockedId)] as $page => $html) {
            $this->assertStringNotContainsString('bg-white', $html, $page.' page uses bg-white');
            $this->assertStringNotContainsString('bg-light', $html, $page.' page uses bg-light');

            // Per-occurrence, so a second card body without the padding fails
            // rather than riding along on the first one's pass.
            preg_match_all('/<div class="([^"]*card-body[^"]*)"/', $html, $bodies);
            $this->assertNotEmpty($bodies[1], $page.' page rendered no card body at all');

            foreach ($bodies[1] as $class) {
                $this->assertStringContainsString('p-4', $class, $page.' page has a card-body without p-4: '.$class);
            }
        }
    }

    /**
     * READ-ONLY, and that is the requirement (`audit-trail.md:19`, DEP-003:73).
     *
     * An audit viewer that grew an edit or delete control would be a severe
     * regression that renders perfectly, passes every other test in this file,
     * and changes the meaning of the whole table. Asserted as an ABSENCE.
     */
    #[Test]
    public function test_neither_page_carries_a_write_affordance(): void
    {
        foreach (['index' => $this->renderIndex(), 'show' => $this->renderShow($this->lockedId)] as $page => $html) {
            $this->assertStringNotContainsString('confirm-action', $html, $page.' page has a confirm trigger');
            $this->assertStringNotContainsString('confirmModal', $html, $page.' page references the confirm modal');
            $this->assertStringNotContainsString('_method', $html, $page.' page carries a method override');
            $this->assertStringNotContainsString('{!!', $html, $page.' page prints raw output');

            // The index legitimately HAS a form — the filter bar is a GET so a
            // filtered URL is shareable. What must not exist is a form that
            // MUTATES. Asserted per form rather than as a blanket "no <form>",
            // which would be red on a correct page and green on a page that added
            // a POST form alongside the filter.
            preg_match_all('/<form\b([^>]*)>/', $html, $forms);

            foreach ($forms[1] as $attrs) {
                $this->assertStringContainsString(
                    'method="GET"',
                    $attrs,
                    $page.' page has a non-GET form: <form'.$attrs.'>'
                );
                $this->assertStringNotContainsString('@csrf', $attrs, $page.' page posts a form');
            }
        }
    }

    /**
     * The detail page has no form at all.
     *
     * Split out from the shared case because the index's GET filter bar is
     * correct there and would mask a form added to the detail page.
     */
    #[Test]
    public function test_the_detail_page_has_no_form_at_all(): void
    {
        $this->assertStringNotContainsString('<form', $this->renderShow($this->lockedId));
    }

    /**
     * The index's only form is the GET filter bar.
     *
     * Asserted here rather than in the shared loop because the detail page
     * legitimately has NO form, so "a form exists" is a precondition of the index
     * alone.
     */
    #[Test]
    public function test_the_index_filter_bar_is_a_get_form_to_the_index_route(): void
    {
        preg_match_all('/<form\b([^>]*)>/', $this->renderIndex(), $forms);

        $this->assertCount(1, $forms[1], 'precondition: exactly one form, the filter bar');
        $this->assertStringContainsString('method="GET"', $forms[1][0]);
        $this->assertStringContainsString(route('activity-logs.index'), $forms[1][0]);
    }

    /**
     * A read-only list gets no selection column.
     *
     * `design-system.md` §Table Conventions: "A list of read-only rows ... gets no
     * checkbox column — a selection affordance with nothing to select against is
     * dead UI". Also why the bulk bar from `pages/users/index` was not copied.
     */
    #[Test]
    public function test_the_index_has_no_bulk_selection_column(): void
    {
        $html = $this->renderIndex();

        $this->assertStringNotContainsString('type="checkbox"', $html);
        $this->assertStringNotContainsString('bulkSelectAll', $html);
        $this->assertStringNotContainsString('bulk-check', $html);
    }

    /**
     * No `input-group` anywhere in the filter bar.
     *
     * Bootstrap reveals a message through `.is-invalid ~ .invalid-feedback`, a
     * SIBLING selector. An input wrapped in `.input-group` makes the feedback a
     * sibling of the GROUP, the rule never matches, and a rejected filter paints
     * a red border and shows nothing — the defect `pages/settings/index` shipped
     * on 18 of 22 fields before Phase 8 fixed it.
     */
    #[Test]
    public function test_the_filter_bar_wraps_no_input_in_an_input_group(): void
    {
        $this->assertStringNotContainsString('input-group', $this->renderIndex());
    }

    /**
     * Every filter control is labelled and programmatically associated.
     *
     * `design-system.md` §Accessibility: a control with only a placeholder is
     * invisible to a screen reader once the user types into it. Each control
     * needs an `id`, and every `<label for>` must point at an `id` that exists.
     */
    #[Test]
    public function test_every_filter_control_has_an_associated_label(): void
    {
        $html = $this->renderIndex();

        // `name` and `id` are DIFFERENT axes — the filter posts by name, the
        // label points by id — so this cannot be a `name` -> `id` lookup.
        // `filter-search` is the id of the control whose name is `search`.
        preg_match_all('/<(?:input|select)\b(?=[^>]*\bname="([^"]+)")(?=[^>]*\bid="([^"]+)")[^>]*>/', $html, $both, PREG_SET_ORDER);
        preg_match_all('/<(?:input|select)\b[^>]*\bname="([^"]+)"[^>]*>/', $html, $named);
        preg_match_all('/<(?:input|select)\b[^>]*\bid="([^"]+)"/', $html, $withId);
        preg_match_all('/<label\b[^>]*\bfor="([^"]+)"/', $html, $labels);

        // `sort` and `direction` are hidden companions, not controls a person
        // operates; they carry no label and must not be required to.
        $visibleNames = array_values(array_diff($named[1], ['sort', 'direction']));

        $this->assertNotEmpty($visibleNames, 'precondition: the filter bar rendered controls');

        // Every visible control must carry an id, or no <label for> can reach it.
        $idless = array_diff($visibleNames, array_column($both, 1));
        $this->assertSame(
            [],
            array_values($idless),
            'these filter controls have no id, so no <label for> can point at them: '.implode(', ', $idless)
        );

        // And every label must resolve to a control that exists — a `for` pointing
        // at nothing is worse than no label, because it looks labelled.
        $orphanLabels = array_diff($labels[1], $withId[1]);
        $this->assertSame(
            [],
            array_values($orphanLabels),
            'these <label for> values point at no control: '.implode(', ', $orphanLabels)
        );

        $this->assertCount(count($visibleNames), $labels[1], 'every visible filter control needs exactly one label');
    }

    /**
     * The card skeleton the design system pins for an index page.
     *
     * `card border-0 shadow-sm mb-4` wrapping `card-body p-4` wrapping
     * `.table-responsive`, and the pagination footer INSIDE the card body —
     * never in `card-footer`, which is reserved for form actions.
     */
    #[Test]
    public function test_the_index_keeps_the_card_skeleton_and_pagination_placement(): void
    {
        $html = $this->renderIndex();

        $this->assertStringContainsString('card border-0 shadow-sm', $html);
        $this->assertStringContainsString('table-responsive', $html);
        $this->assertStringContainsString('table table-hover align-middle mb-0', $html);
        $this->assertStringNotContainsString('card-footer', $html);

        $tableAt = strpos($html, 'table-responsive');
        $paginationAt = strpos($html, 'entries');

        $this->assertIsInt($tableAt);
        $this->assertIsInt($paginationAt, 'the pagination footer did not render');
        $this->assertGreaterThan($tableAt, $paginationAt, 'pagination must follow the table');
    }

    /**
     * The plain column headers carry a scope.
     *
     * `x-ui.sortable-th` renders `<th class="sortable">` WITHOUT `scope`, unlike
     * the plain headers used elsewhere on this page — a pre-existing divergence
     * in a component shared with `pages/permissions` and `pages/roles`. Those two
     * headers are skipped here rather than the component being edited, because
     * changing a shared component is a separate change affecting three modules.
     */
    #[Test]
    public function test_the_plain_column_headers_carry_a_scope(): void
    {
        preg_match_all('/<th\b([^>]*)>/', $this->renderIndex(), $headers);

        $checked = 0;

        foreach ($headers[1] as $attrs) {
            if (str_contains($attrs, 'class="sortable')) {
                continue;
            }

            $checked++;
            $this->assertStringContainsString('scope="col"', $attrs, 'a column header is missing scope="col": <th'.$attrs.'>');
        }

        $this->assertGreaterThan(0, $checked, 'precondition: plain column headers were found');
    }

    /**
     * The column order is what the operator reads, so it is pinned rather than
     * left to whoever edits the table next.
     *
     * `When` is deliberately LAST-but-one: it is a property of the row, not part
     * of what happened, and leading with it puts the least discriminating value
     * where the eye reads first. The Actions column is last, matching every other
     * list in the application.
     */
    #[Test]
    public function test_the_columns_read_event_actor_target_source_when_actions(): void
    {
        preg_match_all('#<th\b[^>]*>(.*?)</th>#s', $this->renderIndex(), $headers);

        // `strip_tags` removes the sort link and the arrow glyph beside it, so
        // what is left is the label alone. Reading the raw inner HTML instead
        // would compare markup and whitespace rather than the column names.
        $labels = array_map(
            fn (string $inner): string => trim(preg_replace('/\s+/', ' ', strip_tags($inner))),
            $headers[1]
        );

        $this->assertSame(
            ['#', 'Event', 'Actor', 'Target', 'Source', 'When', 'Actions'],
            $labels,
            'the audit index column order changed'
        );
    }

    /**
     * The Actions column is the ONLY way into the detail page.
     *
     * The event badge used to be a link too. Two affordances for one URL means
     * the eye has two places to look and neither reads as the real one — and it
     * makes "is this a link?" the answer to a question about the badge's
     * semantics rather than about the row.
     */
    #[Test]
    public function test_the_detail_page_has_exactly_one_link_per_row(): void
    {
        $html = $this->page($this->get(route('activity-logs.index'))->assertOk()->getContent());

        preg_match('#<tbody>(.*?)</tbody>#s', $html, $body);
        preg_match_all('#<tr[^>]*>(.*?)</tr>#s', $body[1] ?? '', $rows);

        $this->assertGreaterThan(0, count($rows[1]), 'precondition: the table rendered rows');

        foreach ($rows[1] as $rowHtml) {
            preg_match_all('#href="([^"]*activity-logs/\d+[^"]*)"#', $rowHtml, $links);

            $this->assertCount(
                1,
                $links[1],
                'a row has '.count($links[1]).' links to a detail page; the badge must not also link'
            );
        }
    }

    /**
     * The Actions control is labelled for a screen reader, not icon-only.
     *
     * The icon is decorative next to the word "Detail"; without `aria-label` the
     * button is announced as "Detail" with no indication of WHICH record, so a
     * screen-reader user tabbing down twenty rows hears the same thing twenty
     * times. The event name in the label is what makes each one distinguishable.
     */
    #[Test]
    public function test_the_detail_control_names_the_record_it_opens(): void
    {
        $html = $this->page($this->get(route('activity-logs.index'))->assertOk()->getContent());

        preg_match_all('/<a\b[^>]*activity-logs\/\d+[^>]*>/', $html, $controls);

        $this->assertGreaterThan(0, count($controls[0]), 'precondition: detail controls rendered');

        foreach ($controls[0] as $control) {
            $this->assertStringContainsString('aria-label="View full audit record for ', $control);
        }
    }

    /**
     * The icon-only Detail control declares a hover label AND the page wires it up.
     *
     * Two halves of one contract, asserted together. `data-bs-title` without the
     * `new bootstrap.Tooltip(...)` driver is markup that renders perfectly and
     * shows nothing on hover — the failure mode this project already hit with a
     * bare `title=` attribute. And the driver without Bootstrap loaded above it
     * throws `bootstrap is not defined`, which kills the rest of the page's script.
     */
    #[Test]
    public function test_the_detail_control_shows_a_hover_label_that_is_wired_up(): void
    {
        // The FULL response, not the `page()` slice: the driver lives in
        // `@push('scripts')`, which the layout emits AFTER `</main>`, so scoping
        // this test to the page body would assert against a document that no
        // longer contains the thing it is looking for. The control regexes below
        // are already specific enough not to need the scoping.
        $html = $this->get(route('activity-logs.index'))->assertOk()->getContent();

        // `PREG_SET_ORDER`, because `$matches[1]` on its own is the FIRST match's
        // capture group and not a per-match list — reading `$control[1]` off a
        // plain string indexes its second CHARACTER, which is how the first
        // version of this assertion compared `'a'` against `''`.
        preg_match_all('#<a\b[^>]*activity-logs/\d+[^>]*>(.*?)</a>#s', $html, $controls, PREG_SET_ORDER);

        $this->assertGreaterThan(0, count($controls), 'precondition: detail controls rendered');

        foreach ($controls as [$openTag, $inner]) {
            $this->assertStringContainsString('data-bs-toggle="tooltip"', $openTag);
            $this->assertStringContainsString('data-bs-title="Detail"', $openTag);

            // Icon-only: the label lives in the tooltip, so text inside the anchor
            // would print "Detail" down every row and the column would read as a
            // button bar rather than a control. An icon with no text is the point.
            $this->assertSame('', trim(strip_tags($inner)), 'the Detail control is not icon-only');
        }

        // The driver, and its position: `layouts/app` loads the Bootstrap bundle
        // at line 59 and pushes this page's script at line 63, so `bootstrap` is
        // already defined when the driver runs.
        $this->assertStringContainsString(
            'new bootstrap.Tooltip(el)',
            $html,
            'no tooltip driver on the page, so the hover label never appears'
        );

        $bundle = strpos($html, 'bootstrap.bundle.min.js');
        $driver = strpos($html, 'new bootstrap.Tooltip(el)');

        $this->assertNotFalse($bundle, 'the layout did not load Bootstrap');
        $this->assertGreaterThan(
            $bundle,
            $driver,
            'the tooltip driver runs before Bootstrap is loaded, so `bootstrap` is undefined and the script dies'
        );
    }

    /**
     * `When` shows the timestamp itself, not "2 hours ago".
     *
     * An audit log is read while reconstructing a sequence, and relative times
     * cannot be ordered against each other at second resolution — which is the
     * one thing the table exists to answer. The format is pinned so a change back
     * to `diffForHumans()` fails here rather than being noticed during an
     * incident.
     */
    #[Test]
    public function test_the_when_column_shows_the_full_timestamp(): void
    {
        // A DISTINCTIVE timestamp, because the assertion reads the FIRST When cell
        // and the setUp rows sit above this one. A value near `now()` would be
        // indistinguishable from theirs, and the first version of this test used
        // exactly that — it passed only while the two rows landed in the same
        // second, and went red on its own hours later with nothing changed.
        $when = '2019-03-04 05:06:07';

        $id = $this->record('user.updated');
        DB::table(config('activitylog.table_name', 'activity_log'))
            ->where('id', $id)
            ->update(['created_at' => $when, 'updated_at' => $when]);

        $html = $this->page($this->get(route('activity-logs.index', ['sort' => 'id', 'direction' => 'desc']))->assertOk()->getContent());

        // The row is the newest by id, so sorting by id descending puts it first
        // and the cell under test is deterministic. Scoped to the CELL rather than
        // the page, because `2019-03-04` could appear anywhere.
        preg_match('#<td class="text-muted fs-7 text-nowrap">\s*([^<]+?)\s*</td>#', $html, $cell);

        $this->assertSame(
            $when,
            trim($cell[1] ?? ''),
            'the When cell does not carry the bare timestamp — a `diffForHumans()` regression would read like this'
        );
    }

    /**
     * The empty state says WHICH kind of emptiness it is.
     *
     * "Nothing has happened yet" and "your filter matched nothing" are different
     * facts; an operator who filtered by an actor and got an empty table needs to
     * know the filter is why. Going through HTTP here because the two states come
     * from `$hasFilters`, which the render fixture cannot vary without restating
     * the controller's logic.
     */
    #[Test]
    public function test_the_empty_state_distinguishes_an_empty_table_from_an_empty_filter(): void
    {
        DB::table(config('activitylog.table_name', 'activity_log'))->delete();

        $html = $this->page($this->get(route('activity-logs.index'))->assertOk()->getContent());
        $this->assertStringContainsString('No audit records yet.', $html);

        $filtered = $this->page(
            $this->get(route('activity-logs.index', ['event' => 'no.such.event']))->assertOk()->getContent()
        );

        $this->assertStringContainsString('No audit records match these filters.', $filtered);
        $this->assertStringNotContainsString('No audit records yet.', $filtered);
    }

    /**
     * The Reset control appears only when a filter is narrowing the list.
     *
     * A Reset button on an unfiltered table is a control that does nothing, which
     * is the same defect as a disabled button: it advertises an action that does
     * not exist.
     */
    #[Test]
    public function test_the_reset_control_appears_only_when_a_filter_is_applied(): void
    {
        $this->assertStringNotContainsString('Reset', $this->page(
            $this->get(route('activity-logs.index'))->assertOk()->getContent()
        ));

        $this->assertStringContainsString('Reset', $this->page(
            $this->get(route('activity-logs.index', ['event' => 'user.locked']))->assertOk()->getContent()
        ));
    }

    /**
     * The detail page shows what actually happened.
     */
    #[Test]
    public function test_the_detail_page_shows_the_record(): void
    {
        $html = $this->renderShow($this->lockedId);

        $this->assertStringContainsString('user.locked', $html);
        $this->assertStringContainsString($this->subject->name, $html, 'the subject label must resolve to a name');
        $this->assertStringContainsString($this->viewer->name, $html, 'the actor label must resolve to a name');
        $this->assertStringContainsString('10.0.0.5', $html, 'the request IP is part of the record');
        $this->assertStringContainsString('manual', $html, 'the event-specific property must be shown');
        $this->assertStringContainsString('Mozilla/5.0', $html, 'the user agent is part of the record');
    }

    /**
     * Properties are escaped, never dumped.
     *
     * `properties` carries caller-supplied data — a caller is a person who chose
     * a username — so a row can contain anything the application accepts as a
     * name. Raw output on this page is a stored-XSS sink on the one page an
     * administrator is most likely to open after an incident.
     */
    #[Test]
    public function test_the_detail_page_never_prints_a_property_raw(): void
    {
        $id = $this->record('user.updated', ['note' => '<script>alert(1)</script>']);

        $html = $this->renderShow($id);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html, 'a property was printed raw');
        $this->assertStringContainsString('&lt;script&gt;', $html, 'the property must be HTML-escaped');
    }

    /**
     * The filter dropdowns are populated from the table, not hand-typed.
     *
     * An event name the application writes must appear as an option the moment it
     * exists. A hard-coded list in Blade looks correct here and goes stale in
     * production.
     */
    #[Test]
    public function test_the_filter_dropdowns_are_built_from_the_data(): void
    {
        $html = $this->renderIndex();

        $this->assertStringContainsString('<option value="user.locked"', $html);
        $this->assertStringContainsString('<option value="auth.login"', $html);
        $this->assertStringContainsString($this->viewer->name, $html, 'the actor dropdown lists actors who wrote rows');

        // The target-type option's VALUE is the stored morph type and must stay
        // that way — the filter posts it back and the action compares it to
        // `subject_type` verbatim, so a prettified value would be a filter that
        // silently returns nothing. What is asserted here is the opposite
        // direction: the human reads a word, not a namespace.
        // `Relation::getMorphAlias()` rather than a literal: the stored value is
        // the alias `user`, and pinning that string here means renaming the alias
        // in `MorphMap::ALIASES` breaks this test for a reason that has nothing to
        // do with what it is proving.
        $this->assertMatchesRegularExpression(
            '#<option value="'.preg_quote(Relation::getMorphAlias(User::class), '#').'"\s*>\s*\n?\s*User\s*#',
            $html,
            'the target-type option must carry the raw stored type as its value and a readable word as its label'
        );
    }

    /**
     * The read model is a read model.
     *
     * Asserted as a property rather than through a route, because the 405
     * assertion in `AuditViewerFilterTest` cannot see a model that would happily
     * accept a payload — a future write route calling
     * `Activity::create($request->validated())` fails here first.
     */
    #[Test]
    public function test_the_activity_model_refuses_mass_assignment(): void
    {
        $this->assertSame(['*'], (new Activity())->getGuarded());
    }
}
