<?php

namespace App\Enums;

/**
 * The lifecycle of one requested audit export.
 *
 * ## Why an enum and not a string
 *
 * Three states, and the UI reads all of them differently: `pending` is a spinner,
 * `ready` is a download link, `failed` is an apology. A bare string column makes
 * those three an `if ($row->status === 'ready')` in the controller, the Blade
 * partial and the job's error path — three comparisons that drift into
 * `== 'completed'` on one of them. `docs/base/ui/design-system.md` fixes the badge
 * colours, `label()` below fixes the words, and both live with the states rather
 * than with the consumers.
 *
 * `failed` exists so a job that dies cannot leave a row stuck at `pending`
 * forever — which is the failure that matters, because a spinner that never
 * resolves is indistinguishable from a slow export until the user gives up.
 */
enum AuditExportStatus: string
{
    case PENDING = 'pending';
    case READY = 'ready';
    case FAILED = 'failed';

    /**
     * The human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Preparing',
            self::READY => 'Ready',
            self::FAILED => 'Failed',
        };
    }

    /**
     * Badge classes for this status, per design-system.md § Badges.
     *
     * Same reasoning as `UserStatusEnum::badgeClass()`: the map belongs beside
     * the states, because a view that maps statuses to colours by raw string is
     * the copy that breaks when a status is added.
     *
     * @return string
     */
    public function badgeClass(): string
    {
        return match ($this) {
            self::PENDING => 'bg-secondary-subtle text-secondary border border-secondary-subtle',
            self::READY => 'bg-success-subtle text-success border border-success-subtle',
            self::FAILED => 'bg-danger-subtle text-danger border border-danger-subtle',
        };
    }

    /**
     * The spinner classes for this status, per design-system.md § States
     * ("Loading | Spinner or skeleton").
     *
     * `pending` is the only state that has something happening to it, and it was
     * rendering the bare word `Preparing` — a word that describes a state without
     * showing that anything is running. That is the failure the design system
     * names: a spinner that resolves into an error nobody saw.
     *
     * Null for the states that are not loading, because a spinner next to `Ready`
     * is a lie about work in progress. The word stays either way, so the state is
     * never carried by motion alone.
     *
     * A method rather than a `@if ($status === …)` in the Blade: `layouts/`
     * partials may not reference an enum case, so a view-side branch would have
     * to compare the raw value string — which is exactly the copy that breaks when
     * a state is added. Same argument as `badgeClass()` above.
     */
    public function spinnerClass(): ?string
    {
        return match ($this) {
            self::PENDING => 'fas fa-spinner fa-spin',
            self::READY, self::FAILED => null,
        };
    }
}
