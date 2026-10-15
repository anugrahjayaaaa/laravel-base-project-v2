<?php

return [

    /*
     * Published by Phase 10 Group D (P10-D3).
     *
     * The point of publishing this file is the two keys below. Everything else is
     * the package default, carried verbatim so an upgrade diff shows only what
     * this project actually decided.
     */

    /*
     * If set to false, no activities will be saved to the database.
     */
    'enabled' => env('ACTIVITY_LOGGER_ENABLED', true),

    /*
     * When the clean-command is executed, all recording activities older than
     * the number of days specified here will be deleted.
     *
     * null, deliberately, where the package default is 365.
     *
     * `docs/base/operations/retention.md` promises "Audit logs — Indefinite (until
     * manual review)". Nothing scheduled `activitylog:clean`, so the promise held
     * by accident rather than by decision — and the day anyone schedules that
     * command, a package default nobody had read would silently delete a year of
     * audit history the documentation says is permanent.
     *
     * Retention for audit rows is a legal decision, not a package default. Set a
     * number here only alongside a change to retention.md.
     */
    'delete_records_older_than_days' => null,

    /*
     * If no log name is passed to the activity() helper
     * we use this default log name.
     */
    'default_log_name' => 'default',

    /*
     * You can specify an auth driver here that gets user models.
     * If this is null we'll use the current Laravel auth driver.
     */
    'default_auth_driver' => null,

    /*
     * If set to true, the subject returns soft deleted models.
     *
     * true, deliberately, where the package default is false.
     *
     * The audit viewer exists to answer "who changed what, and when". A row
     * whose subject was later soft-deleted is exactly the row an incident review
     * needs — with this off, the Target column degrades to a bare "#12" and the
     * operator has to open the record to learn it was a person they know.
     *
     * The package default protects a subject from being *re-hydrated* into a
     * live model. The viewer only reads a label off it (`Activity::subjectLabel()`)
     * and never saves it, so nothing here can resurrect a trashed record.
     */
    'subject_returns_soft_deleted_models' => true,

    /*
     * This model will be used to log activity.
     * It should implement the Spatie\Activitylog\Contracts\Activity interface
     * and extend Illuminate\Database\Eloquent\Model.
     *
     * Left as the package class ON PURPOSE. `App\Models\Activity` extends it and
     * is what the viewer reads; the writer is the package's own instance, which
     * is what keeps `$guarded = ['*']` free of any mass-assignment concern on
     * the write path.
     */
    'activity_model' => \Spatie\Activitylog\Models\Activity::class,

    /*
     * This is the name of the table that will be created by the migration and
     * used by the Activity model shipped with this package.
     */
    'table_name' => env('ACTIVITY_LOGGER_TABLE_NAME', 'activity_log'),

    /*
     * This is the database connection that will be used by the migration and
     * the Activity model shipped with this package. In case it's not set
     * Laravel's database.default will be used instead.
     */
    'database_connection' => env('ACTIVITY_LOGGER_DB_CONNECTION'),
];
