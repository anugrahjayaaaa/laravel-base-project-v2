# Laravel Base Project v2 — Agent Guide

Reusable, API-first, UI-independent Laravel 13 foundation.
**Read `docs/planning/ai-execution-guide.md` before any task.** This file is the
cheat sheet; that guide is the contract.

## Before any task
1. `docs/base/` = the design the code MUST follow. Code that conflicts with
   docs → docs win, change via ADR in `docs/planning/decisions.md`.
2. Check `docs/planning/task-tracker.md` for the task ID, dependencies, and
   acceptance criteria. One small task at a time. Status: `IN_PROGRESS` →
   `REVIEW` → `DONE`. Never skip REVIEW.
3. Load skills `laravel-base-project-conventions` + `laravel-best-practices`
   before touching Laravel code here.

## Stack
- PHP 8.3+, Laravel 13.17, MySQL 8. Blade + AdminLTE 4 + Bootstrap 5.3
  (dark default, themed via `--lbp-*` tokens). No Tailwind, no Vue/React.
- Packages: `spatie/laravel-permission` (RBAC), `spatie/laravel-activitylog`
  (audit), `laravel/sanctum` (API auth), `laravel/pennant` (feature flags),
  `laravel/pulse` (observability), `dedoc/scramble` (dev-only API docs).
- Tests: PHPUnit 12 with `test_*` method prefix (no `#[Test]` attribute).
  Suites: `tests/Unit`, `tests/Feature`, `tests/Arch`. SQLite `:memory:`.
- Redis: cache DB1 (`redis-cli -n 1 FLUSHDB` — plain `FLUSHDB` hits DB0 and
  does NOT clear the Laravel cache).

## Hard rules
- **Never merge/commit to `main`.** Branch `feature/<task-id>-<desc>`.
- Run `php artisan test` before declaring done. Tests green or it is not done.
- Authorization is gated on the **route** (`can:` + `feature:` middleware),
  never in a controller constructor.
- Validation: dedicated FormRequest calling `$request->validated()`. Never
  inline `$request->validate()`.
- Controllers are thin — delegate to Actions/Services. Business logic never
  lives in a view or a controller.
- Audits are written **inside the transaction** by the Action layer (source of
  truth), never by a model observer. Use `Auditable::audit()`.
- Jobs/events dispatch **after commit** (`dispatchAfterCommit()` /
  `DB::afterCommit()`).
- Use Laravel facades only (`Cache`, `Queue`, `RateLimiter`, `Lock`). Never
  Redis-specific APIs in application code.
- UI must stay replaceable. A value the API also needs belongs in an
  Action/Service/model, not a View Composer.
- Never remove a test to make the suite pass. Never weaken security to make a
  test pass. Add a regression test for every bug found.
- New Composer package → document in `docs/planning/changelog.md` + ADR first.
- Docs must match code. Update docs whenever behaviour changes.

## Spec tooling (OpenSpec + Spec Kit)
Both are installed. They overlap, so pick by intent — never run both on the
same unit of work.

| Intent | Tool | Flow |
| --- | --- | --- |
| Incremental change on existing code | OpenSpec | `/opsx-explore` → `/opsx-propose` → `/opsx-apply` → `/opsx-archive` |
| Bug: diagnose then fix | Spec Kit | `specify extension add bug` → `/speckit.bug.assess` → `/speckit.bug.fix` → `/speckit.bug.test` |
| Idea not yet justified | Spec Kit | `specify extension add assess` → `/speckit.assess.*` |
| New module or greenfield feature | Spec Kit | `/speckit.constitution` → `.specify` → `/speckit.specify` → `.plan` → `.tasks` → `.implement` → `.converge` |

- OpenSpec artifacts: `openspec/specs/` + `openspec/changes/`. Brownfield-first,
  edit any artifact anytime, no phase gates. Use it for the 90% case.
- Spec Kit artifacts: `.specify/` (constitution, templates) and
  `.specify/memory/constitution.md`. Use its SDD gates for a feature big enough
  to need a written constitution.
- Project constraints are already encoded in `openspec/config.yaml` (`context`
  + `rules`). Amend there, not in each proposal.
- Specs never override `docs/base/`. A spec that conflicts with them is a bug in
  the spec — fix it, or file an ADR in `docs/planning/decisions.md`.

## Feature flags (Pennant)
Flag OFF → route **403s** (`App\Http\Middleware\EnsureFeatureIsEnabled`) and the
sidebar entry drops for everyone, including superadmins. No bypass.
A declared flag is not an active flag: the `database` store is fail-closed, so
`FeatureFlagSeeder` must write the row or the module 403s.

## Current state
Phases 0–9 done, Phase 10 (audit trail) in progress, 11–17 planned.
Full suite: ~1135 tests / ~4308 assertions. 5 benchmark tests are intentionally
assertion-free. Read `docs/planning/progress.md` for the live picture.

## Traps already paid for
- Caching a non-primitive (Collection / Eloquent model / Carbon) through the
  Laravel Redis store returns `__PHP_Incomplete_Class` on a hit — cache
  primitives, instantiate objects outside `Cache::remember`.
- Spatie role swaps in tests need `syncRoles` (not `assignRole`), a
  `PermissionRegistrar` forget, **and** a clear of `User::can()`'s own
  `canMemo` cache. Skipping the memo clear makes tests pass for the wrong reason.
- `input-group` breaks Bootstrap's sibling `invalid-feedback` selector — error
  text goes invisible. Do not wrap validated inputs in `input-group`.
- i18n is **not implemented**. There is no `lang/` directory, no `ui()`
  helper, no `spatie/language_lines`, and no `__('messages.*')` — every string
  in Blade and in the notification classes is literal English by design
  (`docs/planning/progress.md` defers it to a final project-wide phase). Do
  not write `__()` / `@lang` calls against a source that does not exist, and
  do not treat the absence as a bug to fix inline: a half-adopted i18n is worse
  than none, because half the strings stay English anyway.

## Verify before reporting done
`vendor/bin/pint --test` · `php artisan test` · docs updated · no regression in
shared code paths · manual check of any touched UI flow.
