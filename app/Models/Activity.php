<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

/**
 * One audit row, as the viewer reads it.
 *
 * ## Why this exists when the package already ships a model
 *
 * Spatie's `Activity` is a transport: it knows the table and nothing about what
 * a `subject_type` of `App\Models\User` should be shown as. Every consumer that
 * wanted a readable label would write its own mapping, and the fourth consumer
 * would disagree with the third. This class is the one place that turns a stored
 * morph type into a word, so the index table, the detail page and the (planned)
 * export all read the same answer.
 *
 * ## Read-only is structural, not a convention
 *
 * `$guarded = ['*']` overrides the parent's `$guarded = []`. An audit row is
 * evidence: a mass-assignment path that could rewrite one would let a later
 * `Activity::create($request->validated())` edit the record of something that
 * happened. It costs nothing here — the package writes through its OWN model
 * instance (`ActivitylogServiceProvider::getActivityModelInstance()` news up
 * `activity_model`, which is the Spatie class, not this one) and assigns every
 * attribute directly before calling `save()`, so no mass assignment is involved
 * on the write path either way.
 *
 * ## `typeLabel()` reads both the FQCN and a future morph alias
 *
 * There is no `Relation::morphMap()` in the application today, so
 * `subject_type` holds `App\Models\User`. Phase 10 Group D introduces one and
 * rewrites history to `user`. A label map keyed only on the FQCN would render
 * every existing row as an unknown type the day that lands, so both spellings
 * are accepted here and D5 becomes a backfill rather than a rewrite of the view.
 *
 * @property int $id
 * @property string|null $log_name
 * @property string $description
 * @property string|null $event
 * @property string|null $subject_type
 * @property int|string|null $subject_id
 * @property string|null $causer_type
 * @property int|string|null $causer_id
 * @property \Illuminate\Support\Collection|null $properties
 * @property string|null $batch_uuid
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Model|null $causer
 * @property-read \Illuminate\Database\Eloquent\Model|null $subject
 */
class Activity extends SpatieActivity
{
    /**
     * Nothing is mass-assignable on a model whose rows are a record of the past.
     *
     * `public`, not `protected`: the parent declares it public, and PHP refuses
     * to let a child NARROW a property's visibility — `protected` here is a fatal
     * at class-load, not a lint error.
     *
     * @var array<int, string>
     */
    public $guarded = ['*'];

    /**
     * How a stored morph type is shown to a person.
     *
     * Keys are SNAKE_CASE and the lookup snake-cases whatever it is given, which
     * is load-bearing: `class_basename('App\Models\User')` returns `User`, and a
     * map keyed on the literal `'user'` never matches it — PHP array keys are
     * case-sensitive, so the first version of this rendered every row as
     * "Unknown (App\Models\User)" on both the index and the detail page. The
     * index page looked fine because it shows `subjectLabel()` (the user's name);
     * only the dropdown and the detail page expose the type label, which is
     * exactly why a "renders without error" check missed it.
     *
     * @var array<string, string>
     */
    private const TYPE_LABELS = [
        'user' => 'User',
        'role' => 'Role',
        'feature_flag' => 'Feature Flag',
        'system_setting' => 'System Setting',
    ];

    /**
     * Event name fragment => badge variant, first match wins.
     *
     * See `eventBadgeVariant()` for why the order is the interesting part.
     *
     * @var array<int, array{0: string, 1: string}>
     */
    private const EVENT_BADGE_RULES = [
        // `deactivated` contains `activate`; this must come first or a
        // deactivation is painted as a success.
        ['deactivate', 'warning'],
        // `unlocked` contains `lock`; this must come first or an unlock is a
        // warning.
        ['unlock', 'success'],
        ['lock', 'warning'],
        ['restore', 'success'],
        ['activate', 'success'],
        ['verified', 'success'],
        ['force_deleted', 'danger'],
        ['deleted', 'danger'],
        // A failed attempt is the row an operator scans for: nobody reads a log
        // looking for the routine, they read it looking for the failure.
        ['failed', 'danger'],
    ];

    /**
     * The type of the record this row describes, in words.
     */
    public function typeLabel(): string
    {
        return self::labelForType($this->subjectType());
    }

    /**
     * Turn a stored morph type into a word, for one row or for a whole filter.
     *
     * Static so the filter dropdown can label its options from the same
     * implementation the detail page reads. Handing the view a raw
     * `App\Models\User` and letting it call `class_basename()` itself would be
     * presentation logic in Blade, and the day a morph alias arrives the label
     * would silently differ between the dropdown and the row.
     *
     * "Unknown (App\Models\Whatever)" beats a blank cell: an operator seeing a
     * type they do not recognise still learns that the row exists and what it
     * claimed to point at.
     */
    public static function labelForType(?string $type): string
    {
        if ($type === null || $type === '') {
            return 'None';
        }

        $bare = class_basename($type);
        // `getMorphAlias()` returns the class name itself when no morph map
        // exists, so both spellings are snake-cased and tried. `Str::snake` on the
        // alias handles the future map (`feature_flag`), on the basename handles
        // today's FQCNs (`FeatureFlag`).
        $alias = Relation::getMorphAlias($type);

        return self::TYPE_LABELS[Str::snake($alias)]
            ?? self::TYPE_LABELS[Str::snake($bare)]
            ?? 'Unknown ('.$type.')';
    }

    /**
     * The raw stored type, or null for a subject-less event.
     */
    public function subjectType(): ?string
    {
        return $this->subject_type === '' ? null : $this->subject_type;
    }

    /**
     * Who acted, in words.
     *
     * A null causer is not a bug and not an empty string, but it is
     * TWO different facts, and conflating them destroys the one thing the audit
     * trail exists for: `auth.login_failed` has no authenticated actor, while a
     * row whose `causer_id` outlived a force-deleted user has an actor who simply
     * no longer exists. The first says nobody did it; the second says a named
     * person did it and the record names them by id.
     *
     * Resolving the causer to a NAME is what makes the viewer useful; the row
     * itself only carries an id, and an incident review needs the person.
     */
    public function causerLabel(): string
    {
        if ($this->causer instanceof User) {
            return $this->causer->name !== '' && $this->causer->name !== null
                ? $this->causer->name
                : $this->causer->email;
        }

        // The SYSTEM marker is what `AuditsSystemActivity` writes, and it is the only
        // thing separating a scheduled job from a genuinely unattributed row.
        if ($this->causer_id === null && $this->extra('causer') === 'SYSTEM') {
            return 'System';
        }

        // A `causer_id` whose user is gone: somebody DID this, and the account has
        // since been force-deleted. Checked on the ID, not on the resolved
        // relation — an earlier version tested `$this->causer === null` first and
        // rendered this as "Anonymous", claiming nobody performed an action a named
        // person performed. The id outlives the row, so the honest answer names
        // it, and it is the answer an incident review needs.
        if ($this->causer_id !== null) {
            return 'Deleted user (#'.$this->causer_id.')';
        }

        return 'Anonymous';
    }

    /**
     * The record this row describes, in words.
     *
     * Falls back to the bare id when the subject is gone — a force-deleted user
     * still has audit rows, and an empty subject is the failure this whole view
     * exists to prevent (`docs/base/features/audit-trail.md`).
     */
    public function subjectLabel(): string
    {
        $subject = $this->subject;

        if ($subject instanceof User) {
            return $subject->name !== '' && $subject->name !== null
                ? $subject->name
                : $subject->email;
        }

        if ($subject !== null) {
            return method_exists($subject, 'name')
                ? (string) $subject->name
                : '#'.$subject->getKey();
        }

        if ($this->subject_type === null) {
            return '—';
        }

        return $this->subject_id === null
            ? '—'
            : '#'.$this->subject_id;
    }

    /**
     * Read a property, tolerating a row that has none.
     *
     * ## Why this exists instead of the package's `getExtraProperty()`
     *
     * Spatie's version is `Arr::get($this->properties->toArray(), ...)`. On a row
     * whose `properties` column is NULL — a raw insert, a hand-edited row, or any
     * row written before the column was populated — that is a method call on null
     * and the page 500s. `auditContext()` merges `source`/`ip`/`user_agent` into
     * every row the application writes today, so the column is normally never
     * null; the viewer is the first reader that does not control the writer, and
     * it is the one that has to survive a row it did not create.
     *
     * Found by `AuditViewerFilterTest::test_rows_without_a_source_still_render`,
     * which inserts exactly that row.
     */
    public function extra(string $key, mixed $default = null): mixed
    {
        return $this->properties instanceof Collection
            ? $this->properties->get($key, $default)
            : $default;
    }

    /**
     * The badge variant for this row's event.
     *
     * ## Why this is here and not in the view or the controller
     *
     * An event-name-to-colour map is presentation logic, and the house rule is
     * that it belongs beside `label()` on the model rather than in a
     * `$badgeClass` closure in a controller or a `match()` in Blade. The index
     * table, the detail page and the planned export all ask the same question,
     * and a map copied into each of them is three maps that disagree the first
     * time a rule is added.
     *
     * ## The rules are the project's own, not a new scheme
     *
     * `design-system.md` §Action Color Convention already fixes what the colours
     * MEAN — `success` for activate/unlock/restore/enable, `warning` for
     * deactivate/lock/disable, `danger` for delete. Reusing it means a red row in
     * the audit log says the same thing as a red confirm button, which is the
     * whole reason that convention exists.
     *
     * Order is load-bearing, and the two collisions are the reason:
     *
     * - `deactivate` precedes `activate`, because `deactivated` CONTAINS
     *   `activate` — the other order paints a deactivation green.
     * - `unlock` precedes `lock`, because `unlocked` contains `lock` — the
     *   other order paints an unlock a warning.
     *
     * Both fail silently: the page renders, the badge is simply wrong, and
     * nothing reports it. The model test pins both directions.
     *
     * @return string a variant `x-ui.badge` accepts
     */
    public function eventBadgeVariant(): string
    {
        foreach (self::EVENT_BADGE_RULES as [$needle, $variant]) {
            if (str_contains($this->eventName(), $needle)) {
                return $variant;
            }
        }

        return 'neutral';
    }

    /**
     * The badge variant for this row's source.
     *
     * `system` is the only value worth colour: it means no human pressed the
     * button, which is the fact an operator scanning for it is looking for.
     * `web` and `api` are both ordinary and both read as neutral.
     */
    public function sourceBadgeVariant(): string
    {
        return $this->source() === 'system' ? 'info' : 'neutral';
    }

    /**
     * The properties that are ALWAYS derived rather than chosen.
     *
     * `Auditable::auditContext()` merges these into every row, so they are not
     * event-specific and the detail page shows them in their own card. Listing
     * them here is what lets `detailProperties()` drop them — otherwise the
     * detail page prints the source and the IP twice, on the one page whose job
     * is to show the record exactly as stored.
     *
     * @var array<int, string>
     */
    private const CONTEXT_KEYS = ['source', 'ip', 'user_agent', 'request_id'];

    /**
     * The event-specific properties, with the derived context ones removed.
     *
     * @return array<string, string>
     */
    public function detailProperties(): array
    {
        return array_diff_key($this->displayProperties(), array_flip(self::CONTEXT_KEYS));
    }

    /**
     * Properties flattened into printable strings, for the detail page.
     *
     * A property value can be a string, a number, a bool, null, or a nested
     * array — `UserUpdateAction` passes `changedFields($before, $user)`, and a
     * caller is free to pass anything. Rendering a nested array through `{{ }}`
     * raises "Array to string conversion" on the one page whose entire job is to
     * show what was recorded, so non-scalars are JSON-encoded here rather than
     * in the view.
     *
     * Returns a flat map so the view is a plain `@foreach` over key/value pairs
     * and never has to ask what shape it holds.
     *
     * @return array<string, string>
     */
    public function displayProperties(): array
    {
        if (! $this->properties instanceof Collection) {
            return [];
        }

        $flat = [];

        foreach ($this->properties->all() as $key => $value) {
            $flat[(string) $key] = match (true) {
                $value === null => '—',
                is_bool($value) => $value ? 'true' : 'false',
                is_scalar($value) => (string) $value,
                default => (string) json_encode($value),
            };
        }

        return $flat;
    }

    /**
     * Was this row written by the SYSTEM rather than by a person?
     *
     * Driven by `source`, NOT by the absence of a causer. Those are two different
     * questions and the first version conflated them — which the browser
     * screenshot caught immediately: `AuthLoginCompletedAction` audits
     * `auth.login` with a null causer, so every successful login rendered with a
     * system gear beside the word "Anonymous". A null causer means "no actor was
     * recorded", which is correct for a failed login attempt, and says nothing
     * about whether a machine or a person produced the row.
     *
     * `source` is the real signal: `Auditable::auditContext()` writes `web`/`api`,
     * and `AuditsSystemActivity` overrides it to `system` for a scheduled job.
     */
    public function isSystemGenerated(): bool
    {
        return $this->source() === 'system';
    }

    /**
     * `source` is derived by `Auditable::auditContext()`, so it lives in
     * properties rather than in a column.
     */
    public function source(): string
    {
        return (string) ($this->extra('source') ?? 'unknown');
    }

    /**
     * The event name, falling back to the description.
     *
     * Every current writer sets both, but `event` was added to the table after
     * the fact and the column is nullable — a row from before that migration has
     * a description and no event, and an empty filter chip is worse than the
     * description.
     */
    public function eventName(): string
    {
        return $this->event !== null && $this->event !== ''
            ? $this->event
            : $this->description;
    }
}
