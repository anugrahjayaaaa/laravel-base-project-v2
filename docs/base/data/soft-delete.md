# Soft Delete Strategy

## When to Use

Soft delete records the deletion timestamp instead of physically removing the row.

Use only where semantically appropriate.

## Decision Matrix

| Model | Soft Delete | Reason |
|-------|-------------|--------|
| User | yes | Account lifecycle; may need restoration |
| Audit | no | Audit records are legal/accountability records; never delete |
| Permission | normally no | Permissions are system-wide config; remove via admin UI |
| Settings | normally no | Settings are config; remove via management UI |
| Session | no (lifecycle cleanup) | Sessions are cleaned up by expired_token / session cleanup commands |

## Implementation

- Use Laravel's `SoftDeletes` trait on the model.
- Add `$dates` (or `$casts`) with `deleted_at` as datetime.
- Default query scopes exclude soft-deleted records.
- `forceDelete()` for permanent deletion (admin action only).
- `restore()` to undo soft delete (where applicable).

## Query Behavior

- Default: `Model::all()` excludes soft-deleted records.
- Include soft-deleted: `Model::withTrashed()`.
- Only soft-deleted: `Model::onlyTrashed()`.
- Restore: `Model::withTrashed()->restore()`.

## Considerations

- Indexes: add index on `deleted_at` for large tables.
- Foreign keys: be aware of referential integrity when soft-deleting parents.
- Uniqueness: scope unique constraints to non-deleted records (where applicable).

## ADR References

- ADR-011: Soft delete strategy