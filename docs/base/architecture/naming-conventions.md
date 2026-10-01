# Naming Conventions

## PHP/Laravel Conventions

| Aspect | Convention |
|--------|-----------|
| Classes | PascalCase |
| Methods | camelCase |
| Variables | camelCase |
| Constants | UPPER_SNAKE_CASE |
| Database tables | snake_case, plural |
| Model names | singular PascalCase |
| Foreign keys | `{singular_table}_id` |
| Pivot tables | singular table names, alphabetical (e.g. `role_user`) |
| Migration files | `{date}_{time}_create_table_name_table.php` |
| Config files | snake_case |
| Routes | kebab-case for URI |
| Policy methods | CRUD verb + model (`view`, `create`, `update`, `delete`) |
| Imports | Short import only — no FQCN in code bodies |
| Views | No FQCN, no model queries, no service calls in Blade — supply them as view data |
| Action classes | `{Domain}{Operation}Action` — see [Action Naming](#action-naming-domain-first) |

## Form Request Layout

| Aspect | Convention |
|--------|-----------|
| Path | `app/Http/Requests/V1/{Domain}/{Class}Request.php` |
| Namespace | `App\Http\Requests\V1\{Domain}` |
| Parent | `App\Http\Requests\BaseFormRequest` — every concrete Request |
| Shared behaviour | `app/Concerns/`, namespace `App\Concerns` |

`app/Http/Requests/` holds Form Requests and nothing else. Traits that support
them live in `app/Concerns/` alongside `Actions/Concerns/`, `Jobs/Concerns/`
and `Models/Concerns/` — one place for the pattern, not four.

There is **no channel segment** (`Requests/Api/V1/`) because a Form Request is
consumed by both channels: `StoreRoleRequest` is injected by
`Web/V1/RoleController` and `Api/V1/Role/RoleController`.

**The error contract lives on the base class, not on each Request.** Validation
failures return `{message, errors, code, meta}` — the same `code` and
`meta.request_id` / `meta.timestamp` that `Controller::respond()` emits on
success. Applying `FormatsApiErrors` per-Request let 17 of 21 endpoints fall
through to Laravel's default `{message, errors}` while only the four Auth
requests carried it, and no test covered the difference. Inheritance removes
the possibility of forgetting it.

## Test Naming

| Aspect | Convention |
|--------|-----------|
| Test classes | `{Subject}Test` — mirror the class under test 1:1 |
| DI properties | camelCase, never the class name (`$indexAction`, not `$IndexAction`) |

## Action Naming (Domain-First)

**Every** class in `app/Actions/V1/` is named `{Domain}{Operation}Action`.

The domain prefix is **not decoration** — it is the folder name, repeated in
the class name, so a reader sees the domain before they read the verb and a
grep for one domain never straddles two.

```text
App\Actions\V1\[Domain]\[Domain][Operation]Action
```

| Rule | Right | Wrong |
|------|-------|-------|
| Domain prefix first | `UserCreateAction` | `CreateUserAction` |
| Prefix matches folder | `Role/RoleDeleteAction.php` | `Role/DeleteRoleAction.php` |
| Multi-word operations stay intact | `UserForceDeleteAction` | `UserForceactionDelete` |
| Verb is the tail, not the head | `AuthChangePasswordAction` | `ChangePasswordAction` |

### Current domains

| Folder | Domain | Example |
|--------|--------|---------|
| `Actions/V1/Auth/` | `Auth` | `AuthChangePasswordAction`, `AuthVerifyEmailAction` |
| `Actions/V1/Role/` | `Role` | `RoleAssignAction`, `RoleForceDeleteAction` |
| `Actions/V1/User/` | `User` | `UserCreateAction`, `UserLockAction` |
| `Actions/V1/Permission/` | `Permission` | `PermissionIndexAction` |
| `Actions/V1/System/` | `System` | `SystemUpdateSettingsAction` |
| `Actions/V1/BulkAction/` | `BulkAction` | `BulkActionProcessor` |
| `Actions/Concerns/` | — | `PersistsRole` (trait, no `Action` suffix) |

### Naming by operation type

- Read / list → `{Domain}IndexAction` (`UserIndexAction`, `RoleIndexAction`)
- Create / update → `{Domain}CreateAction`, `{Domain}UpdateAction`
- Delete → `{Domain}DeleteAction`, plus `Restore` / `ForceDelete` variants
- State toggle → `{Domain}ActivateAction`, `LockAction`, `UnlockAction`
- Assignment → `{Domain}AssignAction` (**not** `AssignRolesAction`)
- Naming lives with the domain that *owns the rule*, not the caller:
  assigning a user's roles is `Role/RoleAssignAction`, because the superadmin
  guard it carries is a Role rule even though both callers are User actions.

### Non-Action collaborators

`Handler`, `Processor` and `Request` types use the same domain-first prefix
(`UserBulkActionHandler`, `BulkActionProcessor`). Traits in `Actions/Concerns/`
are named for what they do, not for a domain (`PersistsRole`).

## Test Naming

A test class is named after **the class it tests**, 1:1 — so the test suite
indexes the same way the codebase does.

| Tests | Name |
|-------|------|
| `App\Actions\V1\Role\RoleAssignAction` | `RoleAssignActionTest` |
| `app/Services/HealthCheck/HealthCheckService` | `HealthCheckServiceTest` |

Behaviour that spans classes keeps a behavioural name and says which classes it
covers in the docblock (`RbacApiTest`, `LastSuperadminGuardTest`).

## ID Strategy

- Default ID: integer/bigint
- Use UUID only when there is a concrete requirement.

## Database Naming

- Laravel conventions
- snake_case
- plural table names
- singular foreign key names
- `created_at`, `updated_at`
- `deleted_at` where applicable

## Enum/Lookup Decision

| Case | Use |
|------|-----|
| Static finite values | Enum |
| Dynamic/admin-configurable values | Lookup table |

## Soft Delete Strategy

| Model | Soft Delete? |
|-------|-------------|
| User | yes |
| Audit | no |
| Permission | normally no |
| Settings | normally no |
| Session | lifecycle cleanup rather than soft delete |

## Settings

Runtime SystemSetting keys:
```text
inactivity_lock_enabled
inactivity_lock_days
inactivity_lock_grace_enabled
inactivity_lock_grace_days
password_expiry_enabled
password_expiry_days
password_expiry_warn_days
```

Group settings logically: `security`, `registration`, `mail`, `localization`, `system`.