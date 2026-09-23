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

### UI

Accessed via **System → Settings** in the admin sidebar.

- Toggle switches for `allow_username_change` and `allow_email_change`
- Number inputs for cooldown days (1–365)
- Form validation: integer, min 1, max 365

### API

No dedicated settings API — managed via admin web UI. Phase 6 RBAC will gate access.

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