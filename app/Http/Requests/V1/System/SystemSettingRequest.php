<?php

namespace App\Http\Requests\V1\System;

use App\Models\RoleLookup;
use App\Http\Requests\BaseFormRequest;
use App\Providers\AppServiceProvider;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates system setting update payloads.
 */
class SystemSettingRequest extends BaseFormRequest
{
    /**
     * Settings are administrator-only.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('settings.manage') ?? false;
    }

    /**
     * Define validation rules for all system settings with min/max bounds.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            // System timezone — Phase 14 follow-up. Empty means "use the
            // application default"; otherwise it must be a zone an operator can
            // actually pick. `timezone` rejects a malformed string, the exists
            // rule rejects a valid-but-inactive row the dropdown never offers.
            'app_timezone' => [
                'nullable',
                'string',
                'timezone',
                Rule::exists('timezones', 'name')->where('is_active', true),
            ],

            // Login rate limiting & progressive lockout
            'login_max_attempts' => ['integer', 'min:1', 'max:99'],
            'lockout_base_minutes' => ['integer', 'min:1', 'max:60'],
            'lockout_increment_minutes' => ['integer', 'min:1', 'max:120'],
            'login_rate_limit_per_minute' => ['integer', 'min:1', 'max:120'],
            'login_ip_rate_limit_per_minute' => ['integer', 'min:1', 'max:120'],

            // Password reset / verification rate limits
            'password_forgot_rate_limit' => ['integer', 'min:1', 'max:30'],
            'password_reset_rate_limit' => ['integer', 'min:1', 'max:30'],
            'password_change_rate_limit_per_minute' => ['integer', 'min:1', 'max:30'],
            'password_reset_token_expire_minutes' => ['integer', 'min:1', 'max:1440'],
            'email_verification_rate_limit' => ['integer', 'min:1', 'max:100'],
            'email_verification_token_expire_minutes' => ['integer', 'min:1', 'max:1440'],

            // Password policy & lifecycle
            'password_min_length' => ['integer', 'min:4', 'max:128'],
            'password_require_upper' => ['boolean'],
            'password_require_lower' => ['boolean'],
            'password_require_digit' => ['boolean'],
            'password_require_symbol' => ['boolean'],
            'password_reject_username' => ['boolean'],
            'password_uncompromised' => ['boolean'],
            'password_history_enabled' => ['boolean'],
            'password_history_count' => ['integer', 'min:0', 'max:24'],
            'password_expiry_enabled' => ['boolean'],
            'password_expiry_days' => ['integer', 'min:0', 'max:365'],
            'password_expiry_warn_days' => ['integer', 'min:1', 'max:90'],
            'password_security_sweep_time' => ['nullable', 'date_format:H:i'],
            'inactivity_lock_enabled' => ['boolean'],
            'inactivity_lock_days' => ['integer', 'min:1', 'max:365'],
            'inactivity_lock_grace_enabled' => ['boolean'],
            'inactivity_lock_grace_days' => ['integer', 'min:0', 'max:365'],

            // Email verification
            'email_verification_expire_minutes' => ['integer', 'min:1', 'max:1440'],
            'email_verification_mode' => ['in:public,admin,disabled'],

            // Password reset token (Laravel broker)
            'password_reset_expire_minutes' => ['integer', 'min:1', 'max:1440'],

            // API token lifetime — Phase 12 finding #3. A number plus a unit
            // because the sane values span three magnitudes (5 minutes for a
            // script, 90 days for a phone). The bound is on the NUMBER, applied
            // per unit: 3650 days is a decade and stays the operator's choice,
            // while the smallest window is one minute.
            'api_token_expire_value' => ['integer', 'min:1', 'max:3650'],
            'api_token_expire_unit' => ['in:minutes,hours,days'],

            // Idle browser-session lifetime, same pair and same bounds. The
            // number is per unit, so 1440 minutes and 1440 hours are both
            // accepted; the effective ceiling is the operator's call.
            'web_session_expire_value' => ['integer', 'min:1', 'max:3650'],
            'web_session_expire_unit' => ['in:minutes,hours,days'],

            // Username / email change settings
            'allow_username_change' => ['boolean'],
            'username_change_cooldown_days' => ['integer', 'min:0', 'max:365'],
            'allow_email_change' => ['boolean'],
            'email_change_cooldown_days' => ['integer', 'min:0', 'max:365'],

            // Self-registration
            'registration_enabled' => ['boolean'],
            'registration_rate_limit_per_minute' => ['integer', 'min:1', 'max:30'],

            // API documentation visibility (Phase 12 Group A). A boolean, so an
            // API client can publish or unpublish the docs without a deploy.
            'api_docs_public' => ['boolean'],

            // Empty means "no role"; otherwise it must name a role this viewer
            // is allowed to pick. That set is the very one the dropdown is built
            // from, so the form cannot offer a choice the rules then refuse, or
            // vice versa — the earlier Rule::exists only asked "does this role
            // exist?", which let a non-superadmin set the default to superadmin
            // by posting it directly and make every self-registrant one.
            'registration_default_role' => [
                'nullable',
                'string',
                Rule::in(RoleLookup::visibleTo($this->user())->pluck('name')),
            ],

            // Audit trail export ceiling — Phase 10, §11 P10-E9.
            //
            // An HOUR rather than a minute, which is why the bound tops out at 500
            // where the per-minute limiters stop at 120: at five an hour this is a
            // ceiling an operator never reaches by accident, and the only way to
            // change the cost of one export from the UI is this field.
            'audit_export_rate_limit_per_hour' => ['integer', 'min:1', 'max:500'],

            // Manual retries on ONE failed export. Zero is a real answer, not a
            // hole in the validation: it is how an operator disables the button
            // and sends every failure straight to a human.
            //
            // Ten at the top, and the number is not arbitrary — the job retries
            // three times inside ONE click, so the ceiling at 10 is thirty
            // whole-table CSV builds against a single row. Past that this stops
            // being a retry budget and becomes a way to hammer the database from
            // the audit page.
            'audit_export_max_manual_retries' => ['integer', 'min:0', 'max:10'],

            // Phase 12 P12-2: the three limiters that were still literals.
            // Same bound as the per-minute registration limit — past 120 a value
            // stops being a rate limit and becomes a denial of service against
            // the operator's own workflow.
            'user_state_actions_rate_limit_per_minute' => ['integer', 'min:1', 'max:120'],
            'bulk_action_rate_limit_per_minute' => ['integer', 'min:1', 'max:120'],
            'send_test_mail_rate_limit_per_minute' => ['integer', 'min:1', 'max:120'],

            // Phase 11 A1. The bound is 600 rather than 120 for the reason the
            // ceiling above is not 15: this counter is an IP address, and an IP
            // address can be every mobile subscriber of one carrier. A ceiling
            // that cannot be raised past the point where a legitimate shared
            // address trips it is a denial of service against the monitors that
            // are the only intended callers.
            'health_rate_limit_per_minute' => ['integer', 'min:1', 'max:600'],

            // Phase 11 §13. Per user + IP, so the bound is the per-minute one
            // rather than the health route's 600: this key can only be one
            // account on one address, which is not a whole carrier.
            'queue_retry_rate_limit_per_minute' => ['integer', 'min:1', 'max:120'],

            // Phase 14 Group A — storage driver and capacity.
            //
            // The S3 credential rules are conditional on "no secret stored yet"
            // rather than a bare `required_if`: the form renders a blank field
            // once a credential exists, and a blank field must mean "keep it",
            // not "clear it". Region and bucket have no such escape hatch.
            'storage_default_disk' => ['nullable', 'in:local,s3'],
            'storage_local_root' => ['nullable', 'string', 'max:255'],
            'storage_local_permission' => ['nullable', 'string', 'regex:/^0[0-7]{3}$/'],
            'storage_s3_key' => [
                'nullable',
                'string',
                'max:255',
                Rule::requiredIf(fn (): bool => $this->input('storage_default_disk') === 's3'
                    && ! AppServiceProvider::hasStorageSecret('storage_s3_key')),
            ],
            'storage_s3_secret' => [
                'nullable',
                'string',
                'max:255',
                Rule::requiredIf(fn (): bool => $this->input('storage_default_disk') === 's3'
                    && ! AppServiceProvider::hasStorageSecret('storage_s3_secret')),
            ],
            'storage_s3_region' => ['nullable', 'string', 'max:64', 'required_if:storage_default_disk,s3'],
            'storage_s3_bucket' => ['nullable', 'string', 'max:255', 'required_if:storage_default_disk,s3'],
            'storage_s3_endpoint' => ['nullable', 'url', 'max:255'],
            'storage_s3_url' => ['nullable', 'url', 'max:255'],

            // 0 means "no quota"; the hard guard then measures the disk itself.
            'storage_max_capacity_mb' => ['integer', 'min:0', 'max:10485760'],
            'storage_warning_threshold_percent' => ['integer', 'min:1', 'max:99'],

            // Phase 14 Group C — temporary-file retention. One hour to one
            // year: anything longer is a policy for files nothing promotes out
            // of `tmp`, and the job would never delete them anyway.
            'temp_file_retention_hours' => ['integer', 'min:1', 'max:8760'],

            // Phase 14 Group B — scheduled backup. `backup_enabled` gates the
            // scheduler only; the manual trigger stays reachable while it is
            // off. All four are nullable for the API's partial payload, where
            // an absent key means "leave the stored value alone".
            'backup_enabled' => ['boolean'],
            'backup_schedule' => ['nullable', 'date_format:H:i'],
            // Bounds: a quarter of dailies, a year of weeklies, five years of
            // monthlies. Past these a "retention" number is really "never
            // delete", which the audit trail already owns for a different
            // reason.
            'backup_retention_daily' => ['nullable', 'integer', 'min:1', 'max:90'],
            'backup_retention_weekly' => ['nullable', 'integer', 'min:1', 'max:52'],
            'backup_retention_monthly' => ['nullable', 'integer', 'min:1', 'max:60'],
        ];
    }

    /**
     * Keep a configurable local root inside the application's storage tree.
     *
     * The field is edited by an administrator, which is exactly the trust
     * boundary worth checking: an absolute path anywhere on the host would let a
     * settings save point the private disk at `/etc` and turn every later read
     * into a file-disclosure primitive.
     *
     * A string prefix is not enough: `/…/storage/../etc` starts with the prefix
     * and resolves outside it, and a symlink planted inside the tree resolves
     * outside it as well. Both are resolved before the comparison.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $root = $this->input('storage_local_root');

                if (! is_string($root) || $root === '') {
                    return;
                }

                if (! $this->isInsideStorageTree($root)) {
                    $validator->errors()->add(
                        'storage_local_root',
                        'The storage root must be inside '.storage_path().'.'
                    );
                }
            },
        ];
    }

    /**
     * Does this path resolve inside `storage_path()`?
     *
     * `realpath()` only answers for a path that exists, and the configured root
     * is usually created on first write. So the candidate is normalised
     * lexically first (`..` resolved without touching disk), then the deepest
     * existing ancestor is resolved through the filesystem — which is what
     * turns a symlink into its target — and the missing tail is re-attached.
     */
    private function isInsideStorageTree(string $root): bool
    {
        $storage = realpath(storage_path());

        if ($storage === false) {
            return false;
        }

        $candidate = $this->normalizePath($root);
        $existing = $candidate;
        $tail = [];

        while (realpath($existing) === false && dirname($existing) !== $existing) {
            array_unshift($tail, basename($existing));
            $existing = dirname($existing);
        }

        $resolved = realpath($existing);

        if ($resolved === false) {
            return false;
        }

        $candidate = rtrim($resolved, '/').($tail === [] ? '' : '/'.implode('/', $tail));

        return $candidate === $storage || str_starts_with($candidate.'/', rtrim($storage, '/').'/');
    }

    /**
     * Resolve `.` and `..` segments against the absolute root, no disk access.
     */
    private function normalizePath(string $path): string
    {
        $segments = [];

        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return '/'.implode('/', $segments);
    }

    /**
     * Cast boolean settings from string input to actual booleans before validation.
     */
    protected function prepareForValidation(): void
    {
        foreach (
            [
                'password_require_upper',
                'password_require_lower',
                'password_require_digit',
                'password_require_symbol',
                'password_reject_username',
                'password_uncompromised',
                'password_history_enabled',
                'password_expiry_enabled',
                'inactivity_lock_enabled',
                'inactivity_lock_grace_enabled',
                'allow_username_change',
                'allow_email_change',
                'registration_enabled',
                'api_docs_public',
                'backup_enabled',
            ] as $key
        ) {
            if ($this->has($key)) {
                $this->merge([$key => $this->boolean($key)]);
            }
        }
    }
}
