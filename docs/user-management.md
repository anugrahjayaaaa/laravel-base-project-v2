# User Management — System Settings

## System Settings (P4-F10)

The system settings control user lifecycle behavior via the `system_settings` table.

### Settings

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `allow_username_change` | boolean | `true` | Whether users can change their username |
| `allow_email_change` | boolean | `true` | Whether users can request email change |
| `username_change_cooldown_days` | integer | `30` | Days before username can be changed again |
| `email_change_cooldown_days` | integer | `30` | Days before email can be changed again |
| `registration_enabled` | boolean | `false` | Whether the public sign-up form exists |
| `registration_default_role` | string | `user` | Role given to self-registered accounts; empty means no role |
| `registration_rate_limit_per_minute` | integer | `3` | Sign-up attempts per minute per IP |

### Adding a new setting

Five places, and **the fourth is the one that fails silently**. A key missing
from `SystemSettingsUpdateAction::$updates` is simply never written: the form
posts, validation passes, the success flash shows, and the value does not
change. Nothing errors.

1. `SystemSettingSeeder` — the default
2. `SystemSettingRequest::rules()` — validation (booleans also need adding to
   `prepareForValidation()`, or a string "on" reaches the action)
3. `pages/settings/index.blade.php` — the input
4. **`SystemSettingsUpdateAction::$updates`** — the persistence whitelist
5. Read it with `SystemSetting::getBool/getInt/getString()` — never a class name
   inside a Blade view; views get their values from the controller

`SettingsPersistenceTest` asserts that every key in
`SystemSettingRequest::rules()` is written by the action, so a missing step 4
fails the suite instead of shipping.

### Self-registration

`registration_enabled` off means `/register` returns 404 on both verbs — the
page does not exist rather than the visitor being forbidden, which is what a
403 would imply. The link on the login page is hidden by the same flag.

`UserCreateAction` serves both entry points. Passing no password (the admin
form) generates a temporary one, sets `must_change_password`, and emails it.
Passing the user's own password (self-registration) stores it as given, leaves
`must_change_password` false, and emails a verification link that contains no
password. Roles come from the form for an admin, from
`registration_default_role` for a self-registering user.

Only a password the user chose is written to `password_histories`. A generated
one is never typed by them, so recording it would only stop that exact string
from being picked later.

### UI

Accessed via **System → Settings** in the admin sidebar.

- Toggle switches for `allow_username_change` and `allow_email_change`
- Number inputs for cooldown days (1–365)
- Form validation: integer, min 1, max 365

### API

`POST /api/v1/auth/register` — same `RegisterRequest` and `UserCreateAction` as
the web form, no session or token issued. Returns `201` with
`{ data: { id, username, email } }`. Returns `404` while
`registration_enabled` is off, matching the web route, so a client cannot probe
for a feature the operator turned off. Validation failures return `422` with
`code: VALIDATION_ERROR`. The account still has to use the emailed link before
either channel will accept a login.

### Behavior

- When `allow_username_change = false`: username input disabled on edit form, `canChangeUsername()` returns false
- When `allow_email_change = false`: email change request blocked, `canChangeEmail()` returns false
- Cooldown enforced in `UpdateUserRequest::withValidator()` — rejects change if within cooldown period
- Cooldown displayed as hint text below input field on edit form

## Phase 4F Summary

| Group | Status |
|-------|--------|
| F1 — Migration | DONE |
| F2 — Model methods | DONE |
| F3 — Actions | DONE |
| F4 — Form Requests | DONE |
| F5 — Notification | DONE |
| F6 — Controllers (Web + API) | DONE |
| F7 — Routes | DONE |
| F8 — Views | DONE |
| F9 — Tests | DONE (7/7 pass) |
| F10 — System Settings UI | DONE |