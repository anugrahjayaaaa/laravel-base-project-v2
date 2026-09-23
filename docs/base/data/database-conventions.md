# Database Conventions

## ID Strategy

- Default ID: integer/bigint
- Use UUID only when there is a concrete requirement.

## Naming Conventions

| Element | Convention |
|---------|-----------|
| Tables | snake_case, plural |
| Columns | snake_case |
| Foreign keys | `{singular_table}_id` |
| Pivot tables | singular table names, alphabetical (e.g. `role_user`) |
| Timestamps | `created_at`, `updated_at` |
| Soft delete | `deleted_at` |

## Indexes

- Use indexes based on actual query/access patterns.
- Add indexes on foreign keys by default.
- Composite indexes for multi-column query filters.
- Document rationale for each index.

| Index | Columns | Purpose |
|-------|---------|---------|
| `idx_user_status_composite` | `is_active, is_locked, deleted_at` | Conditional aggregation counts + status filter |

## Constraints

- Use database constraints for data integrity.
- Validation is not a replacement for database constraints.
- Unique constraints on business-identifying fields.
- Foreign key constraints where lifecycle warrants.

## Enum vs Lookup Table

| Case | Use |
|------|-----|
| Static finite values | Enum (PHP 8.1+ backed enums) |
| Dynamic/admin-configurable values | Lookup table |

## Soft Delete Strategy

| Model | Soft Delete? |
|-------|-------------|
| User | yes |
| Audit | no |
| Permission | normally no |
| Settings | normally no |
| Session | lifecycle cleanup rather than soft delete |

Use only where semantically appropriate.

## Cascade Rules

- Cascade allowed where parent-child lifecycle semantically requires it.
- Do not blindly add cascade to every relationship.
- Prefer restricted deletes with explicit error handling for non-essential relationships.

## Conventions in Code

- All migrations use Laravel's schema builder.
- Use `foreignId()` for foreign key columns.
- Use `constrained()` for foreign key constraints.
- Indexes defined at migration level.
- Use `enum()` migration method for static enums; lookup tables for dynamic values.

## ADR References

- ADR-010: Database ID strategy
- ADR-011: Soft delete strategy
- ADR-012: Cascade relationship strategy