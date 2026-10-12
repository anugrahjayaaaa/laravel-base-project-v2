<?php

namespace Tests\Feature\Audit;

use App\Enums\AuditExportStatus;
use App\Http\Middleware\VerifyCsrfToken;
use App\Models\Activity;
use App\Models\AuditExport;
use App\Models\RoleLookup;
use App\Models\User;
use App\Support\SystemRole;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The audit page shows the viewer's own exports, with a download button.
 *
 * ## Why the card exists
 *
 * The request button, the queue and the notification all worked, and none of them
 * put the file one click away: the only route to it was a notification on another
 * page, in a dropdown, or in mail. This is the case that pins the file being
 * reachable from the page that produced it.
 *
 * ## The two states a button must NOT appear in
 *
 * `pending` and `expired` render a status and no button. A disabled download is
 * the standard way to advertise an action that answers 404 or 410, so the button
 * is absent rather than disabled — `isDownloadable()` is the model's single rule,
 * the same one the route enforces.
 */
class AuditExportListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->withoutMiddleware(VerifyCsrfToken::class);
        Storage::fake('local');
    }

    private function login(): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole(RoleLookup::find(SystemRole::ADMIN));

        $this->actingAs($user, 'web');

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function exportFor(User $user, array $overrides = []): AuditExport
    {
        $export = AuditExport::query()->create(array_merge([
            'user_id' => $user->getKey(),
            'filters' => ['event' => 'user.updated'],
            'status' => AuditExportStatus::READY,
            'path' => 'audit-exports/'.$user->getKey().'/file.csv',
            'row_count' => 42,
            'expires_at' => now()->addDays(AuditExport::RETENTION_DAYS),
            'completed_at' => now(),
        ], $overrides));

        if ($export->status === AuditExportStatus::READY && $export->path !== null) {
            Storage::disk('local')->put($export->path, "id,event\n");
        }

        return $export;
    }

    public function test_a_ready_export_offers_a_download_on_the_audit_page(): void
    {
        $user = $this->login();
        $export = $this->exportFor($user);

        $response = $this->get(route('activity-logs.index'));

        $response->assertOk();
        $response->assertSee('Your exports');
        $response->assertSee(route('activity-logs.exports.download', $export), escape: false);
        $response->assertSee('Download');

        // The row says WHICH file this is. "Requested 2026-10-12 09:14" alone
        // cannot be matched against the filter that produced it.
        $response->assertSee('event=user.updated');
        $response->assertSee('42');
        $response->assertSee('Ready');
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function undownloadableProvider(): array
    {
        return [
            'still generating' => [['status' => AuditExportStatus::PENDING, 'path' => null, 'row_count' => null], 'Preparing'],
            'the job failed' => [['status' => AuditExportStatus::FAILED, 'path' => null, 'row_count' => null], 'Failed'],
            'the window closed' => [['expires_at' => now()->subMinute()], 'Expired'],
        ];
    }

    #[DataProvider('undownloadableProvider')]
    public function test_a_row_with_no_file_offers_no_download_button(array $overrides, string $expectedState): void
    {
        $user = $this->login();
        $this->exportFor($user, $overrides);

        $response = $this->get(route('activity-logs.index'));

        $response->assertOk();
        $response->assertSee($expectedState);
        $response->assertDontSee(
            route('activity-logs.exports.download', AuditExport::query()->first()),
            escape: false
        );
    }

    public function test_another_operators_export_is_never_listed(): void
    {
        $user = $this->login();
        $other = User::factory()->create();

        $this->exportFor($other, ['filters' => ['event' => 'role.deleted']]);

        $this->get(route('activity-logs.index'))
            ->assertOk()
            ->assertDontSee('Your exports')
            ->assertDontSee('role.deleted');
    }

    public function test_the_page_never_lists_more_than_five(): void
    {
        $user = $this->login();

        for ($i = 0; $i < 7; $i++) {
            $this->exportFor($user, ['filters' => ['event' => 'user.updated'], 'row_count' => $i]);
        }

        // Five is the shortcut, not an archive. A seventh row is a paginator's
        // problem, and this list is the "did I already do that?" answer.
        $response = $this->get(route('activity-logs.index'));

        $response->assertOk();

        preg_match('/Your exports.*?<\/table>/s', $response->getContent(), $card);

        $this->assertNotEmpty($card, 'precondition: the exports card rendered');
        $this->assertSame(5, substr_count($card[0], 'Download'), 'expected five rows in the exports card');
    }

    public function test_listing_them_writes_no_audit_row(): void
    {
        $user = $this->login();
        $user->audit('user.updated', $user);
        $this->exportFor($user);

        $before = Activity::query()->count();

        $this->get(route('activity-logs.index'))->assertOk();

        $this->assertSame($before, Activity::query()->count());
    }
}
