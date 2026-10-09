<?php

namespace App\Support;

use App\Models\FeatureFlag;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;

/**
 * The morph aliases stored in `activity_log.subject_type` and `causer_type`.
 *
 * ## Why this exists as its own class
 *
 * Two consumers need the identical map and must never disagree: the provider
 * that registers it with Eloquent, and the backfill migration that rewrites the
 * FQCNs already in the table. A literal written twice drifts, and the failure is
 * silent in the dangerous direction — the map says `user`, the backfill wrote
 * `App\Models\User`, and every new row reads as an unmapped type.
 *
 * A migration cannot read the registered map either: it must produce the same
 * answer on a machine that has never booted the provider, and it must keep
 * working after the provider's list changes. So the list lives here and both
 * read it.
 *
 * ## Why aliases at all
 *
 * Without a map, `subject_type` stores `App\Models\User`. That costs three
 * things:
 *
 * - the viewer renders an internal class name, or ships its own name map;
 * - renaming a model silently orphans every historical row that pointed at it,
 *   because the stored string no longer resolves to anything;
 * - the export (Group E) writes the application's namespace into a file handed to
 *   an operator.
 *
 * ## Permissive, not `enforceMorphMap()`
 *
 * Deliberate. `enforceMorphMap()` makes `Model::getMorphClass()` throw
 * `ClassMorphViolationException` for any unmapped class — and that runs on the
 * WRITE path. A model that later gains the `Auditable` trait without being added
 * here would then fail the mutation it is trying to describe, inside a
 * transaction. For an audit trail that is the wrong trade: a missing map entry
 * should cost a row that stores an FQCN, not a 500.
 *
 * Reading is unaffected either way — `Model::getActualClassNameForMorph()` falls
 * back to the stored value when it is not a key in the map, so an FQCN row keeps
 * resolving even before the backfill has run.
 *
 * @see \App\Models\Activity::labelForType() which accepts either spelling.
 */
final class MorphMap
{
    /**
     * Alias => class. The keys are what get STORED; the values are what they
     * resolve to.
     *
     * Every model carrying `Auditable` must appear here. `assert_covers_auditable_models`
     * in `AuditMorphMapTest` fails the suite when one is added and this is not,
     * because the only other signal — an unmapped type in the viewer — reads as
     * "Unknown (App\Models\Whatever)" rather than as an error.
     *
     * @var array<string, class-string<\Illuminate\Database\Eloquent\Model>>
     */
    public const ALIASES = [
        'user' => User::class,
        'role' => Role::class,
        'feature_flag' => FeatureFlag::class,
        'system_setting' => SystemSetting::class,
    ];

    /**
     * The inverse, for the backfill: stored FQCN => alias.
     *
     * Derived rather than written out, so adding an alias above cannot leave a
     * second hand-typed list to update.
     *
     * @return array<class-string<\Illuminate\Database\Eloquent\Model>, string>
     */
    public static function classToAlias(): array
    {
        return array_flip(self::ALIASES);
    }

    /**
     * The inverse of the above: alias => FQCN. Used by the migration's rollback.
     *
     * @return array<string, class-string<\Illuminate\Database\Eloquent\Model>>
     */
    public static function aliasToClass(): array
    {
        return self::ALIASES;
    }
}
