# Concurrency

## Strategies

The base architecture must account for race conditions.

Use appropriate techniques:
- Transactions
- Unique constraints
- Atomic operations
- Database locking where necessary
- Retry strategies where appropriate

## Do NOT

- Do NOT introduce pessimistic locking everywhere.
- Idempotency keys are NOT required globally at this stage — use only where concrete requirement exists.

## Transaction Boundaries

```
Request → Controller → Action/Service → DB transaction → Persist → Commit → Dispatch after-commit
```

- Do not dispatch side effects prematurely when transactional consistency matters.
- Queue jobs, events, notifications dispatched after commit.
- Use `DB::afterCommit()` or `dispatchAfterCommit()` for post-commit side effects.

## Race Condition Patterns

### Check-Then-Act

```php
// BAD: race condition
if (!User::where('email', $email)->exists()) {
    User::create(['email' => $email]);
}

// GOOD: unique constraint + catch
try {
    DB::transaction(function () use ($data) {
        User::create($data);
    });
} catch (UniqueConstraintViolationException $e) {
    // handle
}
```

### Optimistic Locking

Use model version/checksum for optimistic locking on frequently updated records:
```php
// tambahkan kolom version ke model
// gunakan $model->timestamps = true
// increment version on update
```

### Pessimistic Locking

Use only where necessary:
```php
// Lock for update
$model = Model::lockForUpdate()->find($id);
```

## Retry Strategies

For operations prone to deadlocks or concurrent updates:
```php
// retry 3 times with exponential backoff on deadlock
return retry(3, fn() => $this->criticalOperation(), 50);
```