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

## Deletion Policy

Soft delete is supported, but it is NOT mandatory for every entity. Each
entity must have an explicit deletion policy.

| Rule | Detail |
|------|--------|
| Per-entity policy | Each entity decides independently whether soft delete applies |
| Destructive actions | Require confirmation in the UI where applicable |
| Restore behavior | Must be explicitly defined per entity |
| Deleted-record API/search behavior | Must be explicitly defined per entity |
| System-critical records | Must have stronger deletion restrictions (e.g., last superadmin) |
| Audit | Deletion and restoration are audited |

## Cascade Rules (Updated)

Cascade rules ARE allowed in the Base Project when appropriate.

- Cascade delete may be used when child records have no independent lifecycle
  and deletion semantics are unambiguous (e.g., Role deletion cleans up the
  `role_user` pivot).
- Restrict/no-action behavior should be used when deleting the parent would
  create unacceptable data loss (e.g., deleting a User who owns audit records).
- Soft-deleted parents require careful handling of child records — cascade
  must be intentional and documented per relationship.
- Cascade behavior must be intentional and documented per relationship.
- Never blindly add cascade to every foreign key.

This updates ADR-012. Cascade rules are now permitted per-relationship when
justified; they are no longer categorically restricted.

## Considerations

- Indexes: add index on `deleted_at` for large tables.
- Foreign keys: be aware of referential integrity when soft-deleting parents.
- Uniqueness: scope unique constraints to non-deleted records (where applicable).

## ADR References

- ADR-011: Soft delete strategy
- ADR-012: Cascade relationship strategy