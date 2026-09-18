# Registration

## Overview

Public registration is configurable.

## Configuration

```
registration.enabled
registration.default_role
```

## Flow

```
Public registration (if enabled)
  ↓
Validate registration data
  ↓
Assign default role (never allow client to choose role)
  ↓
Send email verification
  ↓
User verifies email
  ↓
Account active (after verification + activation)
```

## Rules

- Public registration form must never allow the client to choose an arbitrary role.
- Default role: `user`.
- The default user has no elevated permissions.
- Do NOT allow public clients to select `superadmin` or `admin`.

## Available Roles (Seeded)

| Role | Description | Publicly assignable? |
|------|-------------|---------------------|
| `superadmin` | Full system access (protected) | No |
| `admin` | Administrative access | No |
| `user` | Default user role | Yes (default) |

## Verification

- Email verification sent on registration.
- Email verification ≠ Account activation.
- User must verify email to proceed.
- After verification, account activation logic applies separately.

## Rate Limiting

- Registration: 5/hour per IP (configurable).

## Security

- Prevent automated registration abuse via CAPTCHA (optional, in custom layer).
- Validate email uniqueness at registration.
- Validate username uniqueness at registration.
- Log registration attempts (successful + failed).
- Notify admin (optional, via settings) of new registrations.

## Customization

Project-specific registration fields and workflows belong in `docs/custom/`.

Base registration provides the core scaffold; custom projects extend as needed.