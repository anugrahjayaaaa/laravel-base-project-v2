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
| Views | No FQCN — move to controller variables (enum cases, settings, model queries) |

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