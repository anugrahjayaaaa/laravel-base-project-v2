<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A feature flag's stored state.
 *
 * ## Why this model exists
 *
 * A feature toggle is a configuration change like any other, and an audit row
 * with no subject is a change you cannot inspect: the viewer shows "#" instead
 * of a name, and there is nothing to link from. The earlier `activity()` call in
 * FeatureToggleAction wrote that row by hand because "a Pennant flag is not a
 * model" — but the state lives in a real `features` table, one row per
 * flag and scope, written by Pennant's database driver. So the row was always
 * there; only the class was missing.
 *
 * Read-only by design. Pennant owns every write to this table — its driver
 * caches, scopes and serialises the value — and a save() from here would fight
 * that rather than add anything. The mutations go through
 * `Feature::activate()` / `Feature::deactivate()`; this model gives those rows an
 * identity so the audit can name them.
 *
 * @property int $id
 * @property string $name
 * @property string $scope
 * @property string $value
 */
#[Fillable(['name', 'scope', 'value'])]
class FeatureFlag extends Model
{
    use Auditable;

    /**
     * @var string
     */
    protected $table = 'features';

    /**
     * Pennant serialises the value, so it is not a boolean column. Reading it
     * through the cast is what turns it back into one.
     */
    public function isEnabled(): bool
    {
        return filter_var($this->value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * The row holding a flag's state.
     *
     * Pennant exposes no way to read back the resolved scope — the resolver set
     * by `AppServiceProvider::resolveScopeUsing()` is write-only — so this cannot
     * filter on it. It does not need to: the app has one scope (`global`) and no
     * per-user or per-tenant flags, so a flag has exactly one row. `orderBy` is
     * there only to make that assumption explicit rather than accidental; the
     * moment a second scope is introduced, this needs the real scope and the
     * first() must go.
     *
     * Null before the flag has ever been read or written, which is a real state
     * and not an error — the catalogue decides the effective value then.
     */
    public static function forName(string $name): ?self
    {
        return static::query()
            ->where('name', $name)
            ->orderBy('id')
            ->first();
    }
}
