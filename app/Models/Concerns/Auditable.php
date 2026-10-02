<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Auditable trait: the single entry point for writing an audit record.
 *
 * ## This is the only place that knows what a row carries
 *
 * Callers pass only what is specific to their event — `identifier`,
 * `lock_duration_seconds`, `remember`. Everything that answers *where did this
 * come from* is derived here, so every row in the table answers it the same way.
 *
 * That matters because the alternative was already tried and failed: each caller
 * assembled its own context, and `request()->is('api/*') ? 'api' : 'web'` ended
 * up copied across six files under two different key names (`source` and
 * `channel`), with only some call sites carrying a user agent. A row written by
 * one path could not be compared with a row written by another.
 *
 * ## Overriding a derived value
 *
 * Pass the key in `$properties` and it wins — that is how a non-HTTP caller (a
 * queued job, say) states `source => system` rather than being mislabelled `web`
 * because there was no request in scope.
 */
trait Auditable
{
    /**
     * Log an audit event on the model.
     *
     * @param string $event Event name
     * @param Model|null $causer User who triggered the event
     * @param array<string, mixed> $properties Event-specific properties. May
     *        carry `source`, `ip` or `user_agent` to override the derived values.
     * @return void
     */
    public function audit(string $event, ?Model $causer = null, array $properties = []): void
    {
        $activity = activity()
            ->on($this)
            ->event($event)
            ->causedBy($causer);

        if ($causer === null) {
            $activity->causedByAnonymous();
        }

        $activity
            ->withProperties(array_merge(static::auditContext(), $properties))
            ->log($event);
    }

    /**
     * The context every audit row carries, derived once.
     *
     * Static and public so a caller with no model to hang the row on — a failed
     * login against an address that is not an account, say — records the same
     * context instead of assembling its own. That was the drift this replaced:
     * six files each deciding what a row should say about where it came from.
     *
     * Merged first so a caller-supplied value overrides it, not the other way
     * round.
     *
     * @return array<string, mixed>
     */
    public static function auditContext(): array
    {
        $request = request();

        return [
            'source' => $request->is('api/*') ? 'api' : 'web',
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ];
    }
}
