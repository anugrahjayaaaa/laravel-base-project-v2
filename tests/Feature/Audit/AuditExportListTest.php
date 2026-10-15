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
     * A failed row is not a dead end: it offers the retry, and the button is a
     * FORM rather than a link because the transition is `failed → pending` and
     * there is no verb for that in a GET.
     *
     * Asserted on the rendered route as well as the label, so the button cannot
     * become decoration pointed at nothing.
     */
    public function test_a_failed_export_offers_a_retry(): void
    {
        $user = $this->login();
        $export = $this->exportFor($user, [
            'status' => AuditExportStatus::FAILED,
            'path' => null,
            'row_count' => null,
        ]);

        $response = $this->get(route('activity-logs.index'));

        $response->assertOk();
        $response->assertSee('Failed');
        $response->assertSee(route('activity-logs.exports.retry', $export), escape: false);
        $response->assertSee('Retry');

        // And it must not be a GET link: a link would render the row's state
        // transition as a page load and change nothing.
        $response->assertSee('method="POST"', escape: false);

        // No file, so no download to offer instead.
        $response->assertDontSee(route('activity-logs.exports.download', $export), escape: false);
    }

    /**
     * Past the budget the button is replaced by a sentence that says who to talk
     * to.
     *
     * "Failed" on its own is a status and not an instruction: the operator cannot
     * tell whether to wait, press something, or ask a person — and a dead button
     * with no explanation is a bug report waiting to happen.
     */
    public function test_an_exhausted_export_asks_the_operator_to_contact_an_admin(): void
    {
        $user = $this->login();
        $this->exportFor($user, [
            'status' => AuditExportStatus::FAILED,
            'path' => null,
            'row_count' => null,
            'retry_count' => AuditExport::maxRetries(),
        ]);

        $response = $this->get(route('activity-logs.index'));

        $response->assertOk();
        $response->assertSee('Contact admin');
        $response->assertDontSee(route('activity-logs.exports.retry', AuditExport::query()->first()), escape: false);
    }

    /**
     * A `pending` row says something is happening. It rendered the bare word
     * `Preparing`, which describes a state without showing that any work is
     * running — the failure `design-system.md` §States names when it asks for a
     * spinner.
     *
     * Asserted per state rather than on the page, because the failure is
     * asymmetric: a spinner next to `Ready` is a lie about work in progress, and
     * only the negative case catches that one.
     *
     * @return array<string, array{0: AuditExportStatus, 1: bool}>
     */
    public static function spinnerProvider(): array
    {
        return [
            'still generating' => [AuditExportStatus::PENDING, true],
            'finished' => [AuditExportStatus::READY, false],
            'died' => [AuditExportStatus::FAILED, false],
        ];
    }

    #[DataProvider('spinnerProvider')]
    public function test_only_a_pending_row_carries_a_spinner(AuditExportStatus $status, bool $expected): void
    {
        $user = $this->login();
        $this->exportFor($user, ['status' => $status, 'path' => null, 'row_count' => null]);

        $response = $this->get(route('activity-logs.index'));

        $response->assertOk();

        if ($expected) {
            $response->assertSee('fa-spinner', false);
            // And the word stays: state is never carried by motion alone, which
            // is also what makes stopping the motion under reduced-motion safe.
            $response->assertSee('Preparing');
        } else {
            $response->assertDontSee('fa-spinner', escape: false);
        }
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

    /**
     * The fragment endpoint is the same partial, scoped the same way.
     *
     * This is what lets a `pending` row resolve without a manual reload, so it has
     * to carry the SAME guarantees as the page it is a part of: the viewer's own
     * rows only, and no audit row written by reading it. A refresh endpoint that
     * leaked another operator's `role.deleted` would undo the scoping the list
     * test above exists to pin.
     */
    public function test_the_fragment_endpoint_returns_the_same_scoped_card(): void
    {
        $user = $this->login();
        $other = User::factory()->create();

        $mine = $this->exportFor($user, ['filters' => ['event' => 'user.updated']]);
        $this->exportFor($other, ['filters' => ['event' => 'role.deleted']]);

        $before = Activity::query()->count();

        $response = $this->get(route('activity-logs.exports.recent'));

        $response->assertOk();
        $response->assertSee('Your exports');
        $response->assertSee(route('activity-logs.exports.download', $mine), escape: false);

        // The other operator's row, and the whole page shell it was never part of.
        $response->assertDontSee('role.deleted');
        $response->assertDontSee('<html', escape: false);

        $this->assertSame($before, Activity::query()->count());
    }

    /**
     * The wrapper the poller swaps is present even with nothing to show.
     *
     * Without it the script has nothing to re-find after the first swap, and the
     * second poll silently never runs — which looks exactly like the poller being
     * broken rather than the markup being wrong. The card heading is still
     * conditional: `test_another_operators_export_is_never_listed` pins that.
     */
    public function test_the_swap_target_exists_with_no_exports_at_all(): void
    {
        $this->login();

        $response = $this->get(route('activity-logs.index'));

        $response->assertOk();
        $response->assertSee('id="audit-exports"', escape: false);
        $response->assertDontSee('Your exports');
    }

    /**
     * Only a `pending` row carries the attribute the poller watches for.
     *
     * The attribute is the enum VALUE, not the label — `Preparing` is wording that
     * could be reworded, `pending` is the state, and the loop stops on the state.
     */
    public function test_only_a_pending_row_marks_the_table_as_having_work_in_flight(): void
    {
        $user = $this->login();
        $export = $this->exportFor($user, ['status' => AuditExportStatus::PENDING, 'path' => null, 'row_count' => null]);

        $this->get(route('activity-logs.exports.recent'))
            ->assertOk()
            ->assertSee('data-status="pending"', escape: false);

        // The SAME row, resolved. Adding a second export would leave the pending
        // one in the list — it is one of five, not one of one — and the assertion
        // would then be testing the wrong thing.
        $export->update([
            'status' => AuditExportStatus::READY,
            'path' => 'audit-exports/'.$user->getKey().'/resolved.csv',
            'row_count' => 7,
            'completed_at' => now(),
        ]);

        $this->get(route('activity-logs.exports.recent'))
            ->assertOk()
            ->assertSee('data-status="ready"', escape: false)
            ->assertDontSee('data-status="pending"', escape: false);
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
