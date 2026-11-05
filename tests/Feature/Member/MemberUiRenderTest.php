<?php

namespace Tests\Feature\Member;

use App\Models\RoleLookup;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Laravel\Pennant\Feature;
use Tests\TestCase;

/**
 * Phase 18 P18-A5: the member screens render from fixtures alone.
 *
 * Rendered by `view()->render()` rather than by HTTP because Group A ships
 * no routes (D-4): a Blade reference to `route('members.index')` would throw
 * until B6 exists, so every URL the screens need arrives as a variable and
 * this test hands over plain strings. The HTTP round trip is proven in E1.
 *
 * The viewer signs in as superadmin: `members.*` permissions are added to
 * the catalogue in D1, and Gate::before is the only way a Group-A fixture
 * render passes a `@can('members.manage')` guard today. The staff case is
 * asserted separately — a guard nobody tested is a guard that may be gone.
 */
class MemberUiRenderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Feature::activate('member_loyalty');
    }

    private function signIn(string $role): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole(RoleLookup::find($role));
        $this->actingAs($user);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function render(string $view, array $data, ?ViewErrorBag $errors = null): string
    {
        return view($view, array_merge(
            ['errors' => $errors ?? new ViewErrorBag()],
            $data,
        ))->render();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function obj(array $overrides = []): object
    {
        return (object) $overrides;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function memberRow(array $overrides = []): object
    {
        return $this->obj(array_merge([
            'id' => 1,
            'member_number' => 'MBR-000001',
            'user_name' => 'Jaya',
            'tier_name' => 'Gold',
            'tier_variant' => 'warning',
            'status_label' => 'ACTIVE',
            'status_variant' => 'success',
            'bulk_state' => 'active',
            'points_balance' => 1200,
            'expires_at' => '2026-12-01',
            'show_url' => '/members/1',
            'delete_url' => '/members/1',
            'user_url' => '/users/3',
            'is_trashed' => false,
            'user_email' => 'jaya@example.test',
            'joined_at' => '2026-01-10',
            'notes' => null,
        ], $overrides));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    private function indexData(array $members = [], array $overrides = []): array
    {
        return array_merge([
            'indexUrl' => '/members',
            'createUrl' => '/members/create',
            'bulkUrl' => '/members/bulk-action',
            'bulkStates' => [
                'active' => ['delete'],
                'expired' => ['delete'],
                'cancelled' => ['delete'],
                'trashed' => ['restore'],
            ],
            'bulkKeys' => ['delete' => 'delete', 'restore' => 'restore'],
            'filters' => ['search' => '', 'sort' => 'member_number', 'direction' => 'asc'],
            'members' => new LengthAwarePaginator(
                $members,
                count($members),
                10,
                1,
                ['path' => '/members']
            ),
            'summary' => [
                $this->obj(['label' => 'Total members', 'value' => 42]),
                $this->obj(['label' => 'Active subscriptions', 'value' => 30]),
            ],
            'tierBadges' => [
                $this->obj(['name' => 'Gold', 'badge_variant' => 'warning', 'count' => 12]),
            ],
        ], $overrides);
    }

    public function test_the_member_index_renders_every_state(): void
    {
        $this->signIn('superadmin');

        $html = $this->render('pages.members.index', $this->indexData([
            $this->memberRow(),
            $this->memberRow([
                'id' => 2,
                'member_number' => 'MBR-000002',
                'tier_name' => null,
                'status_label' => 'EXPIRED',
                'status_variant' => 'danger',
                'bulk_state' => 'expired',
                'show_url' => '/members/2',
                'delete_url' => '/members/2',
            ]),
            $this->memberRow([
                'id' => 3,
                'member_number' => 'MBR-000003',
                'status_label' => 'CANCELLED',
                'status_variant' => 'neutral',
                'bulk_state' => 'cancelled',
                'show_url' => '/members/3',
                'delete_url' => '/members/3',
            ]),
            $this->memberRow([
                'id' => 4,
                'member_number' => 'MBR-000004',
                'is_trashed' => true,
                'bulk_state' => 'trashed',
                'delete_url' => null,
                'show_url' => '/members/4',
            ]),
        ]));

        foreach (['MBR-000001', 'MBR-000002', 'MBR-000003', 'MBR-000004'] as $number) {
            $this->assertStringContainsString($number, $html, "member {$number} is missing from the index");
        }

        foreach (['ACTIVE', 'EXPIRED', 'CANCELLED'] as $status) {
            $this->assertStringContainsString($status, $html, "the {$status} badge is missing");
        }

        // No tier renders as text, never as an empty badge.
        $this->assertStringContainsString('No tier', $html);

        // A trashed row is marked distinctly and lost its delete trigger.
        $this->assertStringContainsString('>DELETED<', $html);
        $this->assertSame(3, substr_count($html, 'data-action-type="delete"'), 'every live row offers exactly one delete trigger');

        // Bulk bar carries the shared driver's contract, not a hand-built URL.
        $this->assertStringContainsString('data-bulk-route="/members/bulk-action"', $html);
        $this->assertStringContainsString('data-bulk-field="member_ids[]"', $html);
        $this->assertStringContainsString('data-status="trashed"', $html);

        // House pagination block.
        $this->assertMatchesRegularExpression('/Showing\s+1\s+to\s+4\s+of\s+4\s+entries/', $html);
    }

    public function test_the_member_index_renders_its_empty_state(): void
    {
        $this->signIn('superadmin');

        $html = $this->render('pages.members.index', $this->indexData());

        $this->assertStringContainsString('No members found.', $html);
    }

    public function test_the_create_member_form_renders_its_contract(): void
    {
        $this->signIn('superadmin');

        $html = $this->render('pages.members.create', [
            'indexUrl' => '/members',
            'createUrl' => '/members',
            'memberNumber' => 'MBR-000101',
        ]);

        $this->assertStringContainsString('action="/members"', $html);
        $this->assertStringContainsString('method="POST"', $html);
        $this->assertStringContainsString('name="_token"', $html);

        foreach (['name', 'email', 'username', 'notes'] as $field) {
            $this->assertStringContainsString('name="'.$field.'"', $html, "the create form lost its {$field} field");
        }

        // The member number is generated, not typed: the field is shown so the
        // operator sees what the member will get, but a disabled input carries
        // no `name` and is never submitted.
        preg_match('/<input[^>]*id="member_number"[^>]*>/', $html, $numberInput);
        $this->assertStringContainsString('disabled', $numberInput[0] ?? '', 'the member number field is editable');
        $this->assertStringNotContainsString('name="member_number"', $html);
        $this->assertStringContainsString('MBR-000101', $html);
    }

    public function test_a_validation_error_lands_on_the_field_that_caused_it(): void
    {
        $this->signIn('superadmin');

        $errors = new ViewErrorBag();
        $errors->put('default', new MessageBag(['email' => ['The email has already been taken.']]));

        $html = $this->render('pages.members.create', [
            'indexUrl' => '/members',
            'createUrl' => '/members',
            'memberNumber' => 'MBR-000101',
            'userOptions' => [7 => 'Jaya (jaya@example.test)'],
        ], $errors);

        preg_match('/<input[^>]*name="email"[^>]*>/', $html, $input);

        $this->assertStringContainsString('is-invalid', $input[0] ?? '', 'an errored field is not marked invalid');
        $this->assertStringContainsString('The email has already been taken.', $html);

        // The message must FOLLOW its input: Bootstrap's `.is-invalid ~
        // .invalid-feedback` selector, and no input-group in between.
        $this->assertLessThan(
            strpos($html, 'The email has already been taken.'),
            strpos($html, 'name="email"'),
            'the error message renders before its field'
        );
    }

    public function test_the_member_detail_renders_the_ledger_and_history_from_passed_collections(): void
    {
        $this->signIn('superadmin');

        $html = $this->render('pages.members.show', [
            'indexUrl' => '/members',
            'adjustUrl' => '/members/1/points',
            'earnRule' => 'Rp 50.000 earns 1.000 points',
            'subscribeUrl' => '/members/1/subscriptions',
            'tierOptions' => [3 => 'Gold — 365 days'],
            'member' => $this->memberRow([
                'user_email' => 'jaya@example.test',
                'points_balance' => 1200,
                'joined_at' => '2026-01-10',
                'notes' => 'Preferred contact by email.',
                'delete_url' => '/members/1',
            ]),
            'subscriptions' => [
                $this->obj([
                    'tier_name' => 'Gold',
                    'status_label' => 'ACTIVE',
                    'status_variant' => 'success',
                    'role_name' => 'gold-member',
                    'starts_at' => '2026-01-10',
                    'expires_at' => '2027-01-10',
                    'cancel_url' => '/subscriptions/5/cancel',
                ]),
                $this->obj([
                    'tier_name' => 'Silver',
                    'status_label' => 'EXPIRED',
                    'status_variant' => 'danger',
                    'role_name' => 'silver-member',
                    'starts_at' => '2025-01-10',
                    'expires_at' => '2026-01-10',
                    'cancel_url' => null,
                ]),
            ],
            'points' => [
                $this->obj(['created_at' => '2026-02-01', 'reason' => 'Manual adjustment', 'delta_display' => '+150', 'delta_variant' => 'success', 'balance_after' => 1200]),
                $this->obj(['created_at' => '2026-03-01', 'reason' => 'Redemption', 'delta_display' => '-30', 'delta_variant' => 'danger', 'balance_after' => 1170]),
            ],
        ]);

        foreach (['+150', '-30', '1200', '1170', 'Manual adjustment', 'gold-member', 'silver-member', '2027-01-10'] as $needle) {
            $this->assertStringContainsString($needle, $html, "the detail page lost {$needle}");
        }

        // Only a cancellable row carries a cancel trigger.
        $this->assertStringContainsString('data-action="/subscriptions/5/cancel"', $html);
        $this->assertStringNotContainsString('data-action="/subscriptions/6/cancel"', $html);

        // Identity block, top to bottom: name, address, then the link to the
        // account the profile hangs off — three lines, each in its own block.
        $this->assertLessThan(strpos($html, 'jaya@example.test'), strpos($html, '>Jaya<'), 'the address renders before the name');
        $this->assertMatchesRegularExpression(
            '/jaya@example\.test\s*<\/div>.*?<a href="\/users\/3"/s',
            $html,
            'the account link is not on the line below the address'
        );

        // The Danger Zone carries its button once the delete URL is live.
        $this->assertStringContainsString('data-action-type="delete"', $html);
        $this->assertStringContainsString('data-action="/members/1"', $html);

        // The empty ledger/history states render as text, not as blanks.
        $empty = $this->render('pages.members.show', [
            'indexUrl' => '/members',
            'adjustUrl' => null,
            'earnRule' => 'Rp 50.000 earns 1.000 points',
            'subscribeUrl' => null,
            'tierOptions' => [],
            'member' => $this->memberRow(['delete_url' => null, 'tier_name' => null, 'notes' => null]),
            'subscriptions' => [],
            'points' => [],
        ]);
        $this->assertStringContainsString('This member has never subscribed.', $empty);
        $this->assertStringContainsString('No points issued or redeemed yet.', $empty);
        // A profile with no delete URL (already trashed) loses the card, not just
        // the button — an empty Danger Zone box is not an affordance.
        $this->assertStringNotContainsString('Danger Zone', $empty);
    }

    public function test_the_tier_list_prints_formatted_prices_and_links_each_row_to_its_pages(): void
    {
        $this->signIn('superadmin');

        $html = $this->render('pages.members.tiers', [
            'indexUrl' => '/members',
            'createUrl' => '/members/tiers/create',
            'tiers' => [
                $this->obj([
                    'id' => 1, 'name' => 'Gold', 'slug' => 'gold',
                    'price_display' => 'Rp 100.000,00',
                    'duration_days' => 100, 'point_multiplier' => '2.00', 'role_name' => 'gold-member',
                    'is_active' => true,
                    'edit_url' => '/members/tiers/1/edit', 'delete_url' => '/members/tiers/1',
                ]),
                $this->obj([
                    'id' => 2, 'name' => 'Silver', 'slug' => 'silver',
                    'price_display' => 'Rp 70.000,00',
                    'duration_days' => 60, 'point_multiplier' => '1.50', 'role_name' => null,
                    'is_active' => false,
                    'edit_url' => '/members/tiers/2/edit', 'delete_url' => '/members/tiers/2',
                ]),
            ],
        ]);

        $this->assertStringContainsString('Rp 100.000,00', $html, 'the price did not arrive pre-formatted');
        $this->assertStringContainsString('>INACTIVE<', $html, 'the inactive tier does not render distinctly');
        $this->assertStringContainsString('<td>—</td>', $html, 'a tier with no role renders a dash, not a blank cell');
        $this->assertStringContainsString('href="/members/tiers/1/edit"', $html);
        $this->assertStringContainsString('data-action="/members/tiers/2"', $html);
        $this->assertStringContainsString('data-method="DELETE"', $html);
        // The inline per-row editor is gone: a read-only list has no field in it.
        $this->assertStringNotContainsString('name="point_multiplier"', $html, 'the tier list still carries an inline editor');
    }

    public function test_the_tier_form_offers_no_superadmin_and_pairs_its_boolean(): void
    {
        $this->signIn('superadmin');

        $html = $this->render('pages.members.tier-form', [
            'indexUrl' => '/members',
            'tiersUrl' => '/members/tiers',
            'formUrl' => '/members/tiers',
            'formMethod' => 'POST',
            'currencySymbol' => 'Rp',
            'roleOptions' => ['member' => 'member', 'staff' => 'staff'],
            'tier' => null,
        ]);

        $this->assertStringContainsString('action="/members/tiers"', $html);
        $this->assertStringContainsString('value="member"', $html);
        $this->assertStringNotContainsString('value="superadmin"', $html, 'the picker offered superadmin');
        $this->assertStringContainsString('Price (Rp)', $html, 'the price field does not name its currency');
        $this->assertStringContainsString('name="is_active" value="0"', $html, 'the boolean checkbox lost its hidden-input pair');

        // An existing tier pre-fills, so an operator editing one is not typing
        // the row back in from the list.
        $edit = $this->render('pages.members.tier-form', [
            'indexUrl' => '/members',
            'tiersUrl' => '/members/tiers',
            'formUrl' => '/members/tiers/1',
            'formMethod' => 'PUT',
            'currencySymbol' => 'Rp',
            'roleOptions' => ['member' => 'member'],
            'tier' => $this->obj(['name' => 'Gold', 'slug' => 'gold', 'price' => '100000.00', 'duration_days' => 100, 'point_multiplier' => '2.00', 'role_name' => 'member', 'is_active' => true, 'sort_order' => 3]),
        ]);
        $this->assertStringContainsString('value="PUT"', $edit);
        $this->assertStringContainsString('value="Gold"', $edit);
        $this->assertStringContainsString('_method', $edit);
    }

    public function test_the_dashboard_renders_with_zero_subscriptions_and_a_gated_broadcast(): void
    {
        $this->signIn('superadmin');

        $html = $this->render('pages.members.dashboard', [
            'indexUrl' => '/members',
            'broadcastUrl' => '/members/notify-expiring',
            'alertWindowDays' => 14,
            'stats' => [
                $this->obj(['label' => 'Active members', 'value' => 30]),
                $this->obj(['label' => 'Expiring soon', 'value' => 0]),
                $this->obj(['label' => 'Points issued', 'value' => 5400]),
            ],
            'expiring' => [],
            'membersByTier' => [
                $this->obj(['label' => 'Gold', 'count' => 12]),
            ],
        ]);

        $this->assertStringContainsString('No subscriptions are expiring inside the alert window.', $html);
        $this->assertStringContainsString('Expiring within 14 days', $html);
        $this->assertStringContainsString('Points issued', $html);

        // The broadcast trigger posts to the route URL it was handed, with
        // its own copy in data-* — the shared modal driver's contract.
        $this->assertStringContainsString('data-action="/members/notify-expiring"', $html);
        $this->assertStringContainsString('data-method="POST"', $html);
        $this->assertStringContainsString('data-title="Send Expiry Alerts"', $html);
    }

    public function test_mutating_affordances_disappear_without_the_permission(): void
    {
        // Staff holds no member permissions; superadmin passes Gate::before.
        // The `@else` branch on tiers proves the guards are real
        // reads, not decoration the flag test never exercises.
        $this->signIn('staff');

        $index = $this->render('pages.members.index', $this->indexData([$this->memberRow()]));
        $this->assertStringNotContainsString('Create Member', $index, 'the create button ignored members.manage');
        $this->assertStringNotContainsString('data-action-type="delete"', $index, 'the row delete ignored members.manage');

        $tiers = $this->render('pages.members.tiers', [
            'indexUrl' => '/members',
            'createUrl' => '/members/tiers/create',
            'tiers' => [$this->obj([
                'id' => 1, 'name' => 'Gold', 'slug' => 'gold', 'price_display' => 'Rp 100.000,00',
                'duration_days' => 100, 'point_multiplier' => '2.00', 'role_name' => null,
                'is_active' => true,
                'edit_url' => '/members/tiers/1/edit', 'delete_url' => '/members/tiers/1',
            ])],
        ]);
        $this->assertStringNotContainsString('Create Tier', $tiers, 'the create button ignored members.manage');
        $this->assertStringNotContainsString('data-action="/members/tiers/1"', $tiers, 'the row delete ignored members.manage');
        $this->assertStringContainsString('Read only', $tiers);

        $dashboard = $this->render('pages.members.dashboard', [
            'indexUrl' => '/members',
            'broadcastUrl' => '/members/notify-expiring',
            'alertWindowDays' => 14,
            'stats' => [],
            'expiring' => [],
            'membersByTier' => [],
        ]);
        $this->assertStringNotContainsString('data-action="/members/notify-expiring"', $dashboard, 'the broadcast trigger ignored members.notify');
    }

    public function test_the_adjust_points_form_appears_only_with_a_write_url(): void
    {
        $this->signIn('superadmin');

        $base = [
            'indexUrl' => '/members',
            'subscribeUrl' => null,
            'tierOptions' => [],
            'member' => $this->memberRow(),
            'subscriptions' => [],
            'points' => [],
        ];

        $with = $this->render('pages.members.show', [...$base,
            'adjustUrl' => '/members/1/points',
            'earnRule' => 'Rp 50.000 earns 1.000 points',
        ]);
        $this->assertStringContainsString('action="/members/1/points"', $with);

        $without = $this->render('pages.members.show', [...$base,
            'adjustUrl' => null,
            'earnRule' => 'Points earning is off',
        ]);
        $this->assertStringNotContainsString('Adjust Points', $without, 'a null adjustUrl still rendered its form');
    }

    public function test_null_write_urls_take_away_every_write_affordance(): void
    {
        // The read-only slice hands null for every URL whose route ships in
        // B6. A null in `action=` or `href=` is a button that goes nowhere —
        // the guards must remove the affordance, not render it broken.
        $this->signIn('superadmin');

        $index = $this->render('pages.members.index', $this->indexData([$this->memberRow()], [
            'createUrl' => null,
            'bulkUrl' => null,
        ]));
        $this->assertStringNotContainsString('Create Member', $index, 'the create button survived a null createUrl');
        $this->assertStringNotContainsString('bulkSelectAll', $index, 'select-all survived a null bulkUrl');
        $this->assertStringNotContainsString('data-bulk-route', $index, 'the bulk bar survived a null bulkUrl');

        $dashboard = $this->render('pages.members.dashboard', [
            'indexUrl' => '/members',
            'broadcastUrl' => null,
            'alertWindowDays' => 14,
            'stats' => [],
            'expiring' => [],
            'membersByTier' => [],
        ]);
        $this->assertStringNotContainsString('data-action=', $dashboard, 'the broadcast trigger survived a null broadcastUrl');
    }

    public function test_the_flag_hides_every_member_screen(): void
    {
        $this->signIn('superadmin');
        Feature::deactivate('member_loyalty');

        $index = $this->render('pages.members.index', $this->indexData());
        $this->assertStringNotContainsString('bulkSelectAll', $index, 'the index ignores the feature flag');

        $dashboard = $this->render('pages.members.dashboard', [
            'indexUrl' => '/members',
            'broadcastUrl' => '/members/notify-expiring',
            'alertWindowDays' => 14,
            'stats' => [],
            'expiring' => [],
            'membersByTier' => [],
        ]);
        $this->assertStringNotContainsString('Expiring within 14 days', $dashboard, 'the dashboard ignores the feature flag');
    }

    public function test_no_member_view_reaches_for_the_database_routes_or_inline_script(): void
    {
        $pages = [
            'index', 'create', 'show', 'tiers', 'tier-form', 'dashboard',
        ];

        $forbidden = [
            '/\bDB::/' => 'queries the database',
            '/::query\s*\(/' => 'starts a model query',
            '/::where\s*\(/' => 'runs a model query',
            '/SystemSetting::/' => 'reads settings itself',
            '/route\(/' => 'references a route name (the member routes ship in B6, D-4)',
            '/@php/' => 'embeds a PHP block',
            '/<script>/' => 'carries an un-nonceable inline script',
            '/\son(click|submit|load|error|change|keyup|keydown)=/' => 'carries an inline event handler',
            '/bg-(white|light)\b/' => 'uses a class the design system forbids',
            '/input-group/' => 'wraps a validated input in the sibling-selector trap',
        ];

        foreach ($pages as $page) {
            $source = preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents(
                resource_path('views/pages/members/'.$page.'.blade.php')
            ));

            foreach ($forbidden as $pattern => $why) {
                $this->assertDoesNotMatchRegularExpression(
                    $pattern,
                    $source,
                    "pages/members/{$page}.blade.php {$why}"
                );
            }
        }
    }
}
