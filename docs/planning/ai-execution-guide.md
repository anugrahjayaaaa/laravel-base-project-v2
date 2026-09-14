# AI Execution Guide

> Explains how an AI agent should work on this repository. READ THIS BEFORE starting any task.

## Rules

1. **Read relevant architecture documentation** before changing code. Every doc in `docs/base/` defines the design the code MUST follow.

2. **Read the task tracker** (`docs/planning/task-tracker.md`) before starting work. Find the task by ID. Follow its dependencies and acceptance criteria.

3. **Work on one small task at a time.** Pick a task in `READY` or `PLANNED` status. Change it to `IN_PROGRESS`.

4. **Do not implement future tasks** unless required as a dependency. Stay in scope.

5. **Do not silently change architecture decisions.** If you find a conflict, STOP and document it (see #6).

6. **If an architecture conflict is discovered**, stop and document it in `docs/planning/decisions.md` as a new ADR or note the conflict. Do not override existing docs.

7. **Do not modify unrelated features.** Scope creep is the #1 AI mistake.

8. **Run relevant tests** after changes. Tests must be green before commit.

9. **Update task status only after verification.** Move `IN_PROGRESS` → `REVIEW` → `DONE`. Never skip REVIEW.

10. **Update documentation** when behavior changes. If the code does X but docs say Y, the docs are wrong — fix the docs.

11. **Update changelog** (`docs/planning/changelog.md`) when architecture/planning changes. Only meaningful changes.

12. **Add regression tests** for discovered bugs. Always.

13. **Never remove a test** simply to make the suite pass. Ever.

14. **Never weaken security** to make a test pass. If auth breaks, fix the auth, not the test.

15. **Never introduce a package** without documenting its purpose. Add it to `docs/planning/changelog.md` with: name, purpose, why not build custom, security considerations.

16. **Avoid duplicate business logic.** Search for existing implementations first.

17. **Follow Laravel conventions** unless there is a documented reason not to.

18. **Prefer simple maintainable solutions** over unnecessary abstraction. No factory for one product. No interface with one implementation. No config for a value that never changes.

19. **Do not over-engineer.** The smallest change that works, once you understand the problem.

20. **Before completing a task, verify** ALL of: implementation, tests, security, documentation, regression impact.

## Dependency-Aware Execution Rules

21. **Read dependency governance** (`docs/base/governance/dependency-governance.md`)
    before introducing any Composer package. No `composer require` without
    selecting a package documented in `docs/base/dependencies/overview.md`
    and approved via ADR.
22. **Use established packages** for capabilities better provided by mature
    ecosystem solutions (RBAC, audit, monitoring). Do NOT build custom
    implementations of these concerns.
23. **Prefer Laravel-native** for capabilities the framework provides
    (validation, rate limiting, notifications, etc.). Do NOT introduce a
    package for native functionality.
24. **Route package calls through abstractions** where architectural decision
    requires it (Sanctum through Auth abstraction, Activitylog through Audit
    abstraction, Spatie Permission through Gate/Policy).
25. **Telescope and Scribe are not application dependencies** — Telescope is
    a technical tool (disabled in production), Scribe is dev-only. Application
    code must never import/depend on them.
26. **Redis is an infrastructure option, not an application dependency.** Use
    Laravel facades (`Cache`, `Queue`, `RateLimiter`, `Lock`) exclusively —
    never Redis-specific APIs.
27. **Backup is infrastructure, not business logic.** `spatie/laravel-backup`
    is scheduled via the Kernel; application code does not call it directly.

## Transaction and After-Commit Rules

28. **Use database transactions** for multi-step mutations.
29. **Dispatch jobs/events after commit** using `dispatchAfterCommit()` or
    `DB::afterCommit()`. Do NOT dispatch before commit for jobs that depend
    on committed database state.
30. **Audit records are written within the transaction** and only persist
    if the transaction commits. A rolled-back transaction must NOT leave a
    false-success audit record.

## Implementation Discipline Rules

31. **Business mutation source of truth = mutation caller.** The Action/Service
    layer calls the audit abstraction — not model observers.
32. **Authorization source of truth = Policy/Gate layer.** Enforce via
    `authorize()` in Form Requests and `can:` middleware on routes.
33. **Serialization source of truth = Resource layer.** Resources must not
    perform mutations or contain business logic.
34. **Asynchronous work source of truth = Job.**
35. **Do not version API application logic unless behavior diverges.**
    If V1 and V2 share behavior, both call the same shared Action/Service.

## Task Workflow

```
1. Read docs/base/ for relevant architecture
2. Read docs/planning/task-tracker.md → find task by ID
3. Check dependencies → are they DONE?
4. Set task status → IN_PROGRESS
5. Implement code (following architecture docs)
6. Write/run tests → GREEN
7. Update docs if behavior changed
8. Mark task → REVIEW
9. Peer review (or self-review checklist)
10. Mark task → DONE
11. Update progress.md
12. Update changelog if architecture/planning changed
```

## File Locations

| What | Where |
|------|-------|
| Architecture docs | `docs/base/architecture/` |
| Architecture components | `docs/base/architecture/application-components.md` |
| Dependency docs | `docs/base/dependencies/` |
| Dependency governance | `docs/base/governance/dependency-governance.md` |
| Security docs | `docs/base/security/` |
| API docs | `docs/base/api/` |
| Data docs | `docs/base/data/` |
| Infrastructure docs | `docs/base/infrastructure/` |
| Feature docs | `docs/base/features/` |
| Testing docs | `docs/base/testing/` |
| Operations docs | `docs/base/operations/` |
| Requirements | `docs/planning/requirements.md` |
| Feature matrix | `docs/planning/feature-matrix.md` |
| Roadmap | `docs/planning/implementation-roadmap.md` |
| Task tracker | `docs/planning/task-tracker.md` |
| Dependency map | `docs/planning/dependency-map.md` |
| QA tracker | `docs/planning/qa-tracker.md` |
| Changelog | `docs/planning/changelog.md` |
| ADRs | `docs/planning/decisions.md` |
| Progress | `docs/planning/progress.md` |
| Custom docs | `docs/custom/` |

## Git Workflow

- Branch per task: `feature/{task-id}-{short-description}`
- Commit after task is DONE + verified
- Push + PR after explicit approval
- NEVER commit to main directly
- NEVER commit secrets (`.env`, credentials)
- NEVER commit generated documentation that isn't part of the base

## Verification Checklist (per task)

| Check | How |
|-------|-----|
| Implementation | Code follows architecture docs |
| Tests | `php artisan test` or `vendor/bin/pest` — GREEN |
| Security | Input validation, auth boundary, no secret leakage |
| Documentation | Docs match code; updated if behavior changed |
| Regression | Run broader test suite; no previously working features broken |

## Priority Model Reminder

```
P0 = Security/core foundation/blocker
P1 = Essential base-project capability
P2 = Important reusable capability
P3 = Optional/future enhancement
```

Do not classify everything as P0/P1. Use P2/P3 liberally for non-core items.