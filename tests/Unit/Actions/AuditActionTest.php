<?php

namespace Tests\Unit\Actions;

use App\Actions\V1\Audit\AuditExportRequestAction;
use App\Actions\V1\Audit\AuditIndexAction;
use App\Actions\V1\Audit\AuditShowAction;
use App\Enums\AuditExportStatus;
use App\Jobs\GenerateAuditExport;
use App\Models\AuditExport;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * The export request action: the row it creates, the window it sets, and the
 * list scoped to its owner.
 *
 * Retry and download live in `tests/Feature/Audit/AuditExportTest.php` — this
 * file owns the two entry points that have no other direct owner.
 */
class AuditActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_request_creates_an_owned_pending_row_and_queues_the_job(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        $export = app(AuditExportRequestAction::class)->run($user, ['event' => 'user.updated'], 'asc');

        $this->assertSame($user->getKey(), $export->user_id);
        $this->assertSame(['event' => 'user.updated'], $export->filters);
        // The status is the column default, so it only appears after a re-read.
        $this->assertSame(AuditExportStatus::PENDING, $export->fresh()->status);
        $this->assertNull($export->path);

        // The window starts at the REQUEST, so the download route can answer
        // "expired?" without knowing when a worker finished.
        $this->assertEqualsWithDelta(
            now()->addDays(AuditExport::RETENTION_DAYS)->timestamp,
            $export->expires_at->timestamp,
            5,
        );

        Queue::assertPushed(
            GenerateAuditExport::class,
            fn (GenerateAuditExport $job): bool => $job->afterCommit === true,
        );
    }

    public function test_the_recent_list_is_the_viewers_own_newest_first_capped_at_five(): void
    {
        $viewer = User::factory()->create();
        $other = User::factory()->create();

        $exports = collect(range(1, 7))
            ->map(fn (): AuditExport => AuditExport::query()->create([
                'user_id' => $viewer->getKey(),
                'filters' => [],
                'expires_at' => now()->addDays(AuditExport::RETENTION_DAYS),
            ]));

        AuditExport::query()->create([
            'user_id' => $other->getKey(),
            'filters' => [],
            'expires_at' => now()->addDays(AuditExport::RETENTION_DAYS),
        ]);

        $recent = app(AuditExportRequestAction::class)->recentFor($viewer);

        // Another operator's extract never appears on this list, and it is a
        // shortcut to the last few files, not a management screen.
        $this->assertCount(5, $recent);
        $this->assertSame($exports->reverse()->take(5)->pluck('id')->all(), $recent->pluck('id')->all());
    }

    // === index ===

    public function test_the_list_paginates_ten_a_page_newest_first_with_a_stable_tiebreak(): void
    {
        $user = User::factory()->create();

        // One batch: every row shares created_at, which is the case the second
        // order clause exists for.
        foreach (range(1, 12) as $i) {
            $user->audit('audit.probe', $user, ['n' => $i]);
        }

        $page = app(AuditIndexAction::class)->run([]);

        $this->assertSame(10, $page->perPage());
        $this->assertSame(12, $page->total());
        $ids = collect($page->items())->pluck('id')->all();
        $this->assertSame($ids, collect($ids)->sortDesc()->values()->all());
    }

    public function test_the_option_lists_are_derived_from_the_rows_that_exist(): void
    {
        $causer = User::factory()->create(['name' => 'Ada']);
        $subject = User::factory()->create();
        $subject->audit('user.updated', $causer);

        $action = app(AuditIndexAction::class);

        $this->assertContains('user.updated', $action->eventOptions());
        $this->assertArrayHasKey($causer->getKey(), $action->causerOptions());
        $this->assertSame('Ada', $action->causerOptions()[$causer->getKey()]);
        // Keyed by the morph ALIAS the row stores, not the class name: the
        // filter posts the key back and compares it to `subject_type` verbatim.
        $this->assertArrayHasKey($subject->getMorphClass(), $action->subjectTypeOptions());
    }

    // === show ===

    public function test_the_detail_read_returns_the_row_and_refuses_an_unknown_id(): void
    {
        $user = User::factory()->create();
        $user->audit('user.updated', $user);
        $row = Activity::query()->where('event', 'user.updated')->sole();

        $this->assertTrue(app(AuditShowAction::class)->run($row->getKey())->is($row));

        $this->expectException(ModelNotFoundException::class);
        app(AuditShowAction::class)->run(999999);
    }
}
