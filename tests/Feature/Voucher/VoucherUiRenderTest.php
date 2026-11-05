<?php

namespace Tests\Feature\Voucher;

use App\Models\RoleLookup;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\ViewErrorBag;
use Laravel\Pennant\Feature;
use Tests\TestCase;

/**
 * P19-A6 (moved out of Phase 18 per the owner): the voucher screen is its own
 * domain — its own flag, its own menu, validated at payment time — so it owns
 * its own render gate.
 *
 * Fixture render, not HTTP: the voucher routes ship with the Phase 19 B group,
 * so every URL arrives as a variable (same D-4 carve-out the member screens
 * used). The viewer signs in as superadmin because `vouchers.*` permissions
 * land in PermissionCatalog only at P19-D1.
 */
class VoucherUiRenderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Feature::activate('vouchers');
    }

    private function signIn(string $role): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole(RoleLookup::find($role));
        $this->actingAs($user);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function obj(array $overrides = []): object
    {
        return (object) $overrides;
    }

    /**
     * @return array<string, mixed>
     */
    private function pageData(array $overrides = []): array
    {
        return array_merge([
            'types' => ['percentage', 'fixed'],
            'vouchers' => [
                $this->obj([
                    'id' => 1, 'code' => 'LAUNCH10', 'type' => 'percentage', 'value' => '10.00',
                    'max_discount' => '50000.00', 'min_purchase' => '100000.00', 'quota' => 100,
                    'claimed_count' => 7, 'per_member_limit' => 1, 'starts_at' => '2026-11-01',
                    'expires_at' => '2026-12-31', 'is_active' => true, 'update_url' => '/vouchers/1',
                ]),
                $this->obj([
                    'id' => 2, 'code' => 'GONE', 'type' => 'fixed', 'value' => '25000.00',
                    'max_discount' => null, 'min_purchase' => '0.00', 'quota' => null,
                    'claimed_count' => 0, 'per_member_limit' => 2, 'starts_at' => '2026-10-01',
                    'expires_at' => '2026-10-31', 'is_active' => false, 'update_url' => '/vouchers/2',
                ]),
            ],
        ], $overrides);
    }

    private function render(array $data = [], ?ViewErrorBag $errors = null): string
    {
        return view('pages.vouchers.index', array_merge(
            ['errors' => $errors ?? new ViewErrorBag()],
            $this->pageData($data),
        ))->render();
    }

    public function test_voucher_rows_render_quota_as_handed_and_never_compute_remaining(): void
    {
        $this->signIn('superadmin');
        $html = $this->render();

        $this->assertStringContainsString('value="LAUNCH10"', $html);
        $this->assertStringContainsString('<td>7</td>', $html, 'claimed count was not rendered as handed over');
        $this->assertStringContainsString('placeholder="Unlimited"', $html, 'an unlimited quota lost its distinct rendering');
        $this->assertStringContainsString('>INACTIVE<', $html);
        $this->assertStringContainsString('<option value="percentage" selected>', $html);
        $this->assertStringContainsString('action="/vouchers/1"', $html);
        $this->assertStringContainsString('name="is_active" value="0"', $html, 'the boolean checkbox lost its hidden-input pair');
        $this->assertStringNotContainsString('<td>93</td>', $html, 'the view computed remaining quota');
    }

    public function test_voucher_rows_render_empty_state_without_membership_context(): void
    {
        $this->signIn('superadmin');
        $html = $this->render(['vouchers' => []]);

        $this->assertStringContainsString('No vouchers yet.', $html);
        $this->assertStringNotContainsString('Back to Members', $html, 'the voucher page still pointed at the member domain');
    }

    public function test_the_save_button_disappears_without_the_permission(): void
    {
        $this->signIn('staff');
        $html = $this->render();

        $this->assertStringNotContainsString('>Save</button>', $html, 'the voucher save button ignored vouchers.manage');
    }

    public function test_the_flag_hides_the_voucher_screen_and_no_other_flag_touches_it(): void
    {
        $this->signIn('superadmin');
        Feature::deactivate('vouchers');
        Feature::activate('member_loyalty');

        $html = $this->render();
        $this->assertStringNotContainsString('LAUNCH10', $html, 'the voucher screen ignores its own feature flag');
    }

    public function test_the_voucher_view_reaches_for_no_database_routes_or_inline_script(): void
    {
        $source = preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents(
            resource_path('views/pages/vouchers/index.blade.php')
        ));

        $forbidden = [
            '/\bDB::/' => 'queries the database',
            '/::query\s*\(/' => 'starts a model query',
            '/::where\s*\(/' => 'runs a model query',
            '/SystemSetting::/' => 'reads settings itself',
            '/route\(/' => 'references a route name (the voucher routes ship with Phase 19 group B)',
            '/@php/' => 'embeds a PHP block',
            '/<script>/' => 'carries an un-nonceable inline script',
            '/\son(click|submit|load|error|change|keyup|keydown)=/' => 'carries an inline event handler',
            '/bg-(white|light)\b/' => 'uses a class the design system forbids',
            '/input-group/' => 'wraps a validated input in the sibling-selector trap',
        ];

        foreach ($forbidden as $pattern => $why) {
            $this->assertDoesNotMatchRegularExpression($pattern, $source, "pages/vouchers/index.blade.php {$why}");
        }
    }
}
