# Database Relationships

## Common Patterns

### One-to-Many

```php
// Parent
public function children(): HasMany
{
    return $this->hasMany(Child::class, 'parent_id');
}

// Child
public function parent(): BelongsTo
{
    return $this->belongsTo(Parent::class, 'parent_id');
}
```

### Many-to-Many (Polymorphic)

```php
// Tags on multiple models
public function tags(): MorphToMany
{
    return $this->morphToMany(Tag::class, 'taggable');
}
```

### Many-to-Many (Standard)

```php
public function roles(): BelongsToMany
{
    return $this->belongsToMany(Role::class, 'role_user');
}
```

### Has-One / Has-Many-Through

```php
// User → Profile
public function profile(): HasOne
{
    return $this->hasOne(Profile::class, 'user_id');
}

// User → Posts → Comments → comment count
```

### Polymorphic

```php
// Post → Image, User → Image
public function image(): MorphOne
{
    return $this->morphOne(Image::class, 'imageable');
}
```

## Loading Strategy

| Strategy | When |
|----------|------|
| Eager loading (`with`) | When you know all results will access the relation |
| Lazy loading | Never in loops (N+1); avoid in production |
| Lazy loading (allowed) | Single record access, not in loops |
| `load()` vs `with()` | `with()` for known relations; `load()` for conditional loading |

## Foreign Key Constraints

Use `cascade` only when parent-child lifecycle semantically requires it.

| Relationship | Cascade Delete? | Reason |
|-------------|----------------|--------|
| User → Posts | Conditional | Only if posts have no independent value outside user |
| Role → role_user | Yes | Role deletion should clean up assignments |
| Permission → role_permission | Yes | Same as role |

## Performance Notes

- Always eager-load relations used in loops.
- Use `select()` to limit columns when full models aren't needed.
- Consider query builder joins for read-heavy aggregate queries.
- Index foreign keys and frequently queried columns.

## ADR References

- ADR-012: Cascade relationship strategy