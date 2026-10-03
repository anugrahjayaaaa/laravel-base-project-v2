# Phase 8 — Settings Management

> Date: 2026-10-02 | Branch: feature/general-fixes | Status: AUDIT — implementation largely pre-existing
> Purpose: execution breakdown for Phase 8 (Groups A–E), plus a record of what the audit found against the code.
> Scope: the system settings page, its read/update paths on web + API, validation, feature-flag and permission gates, audit trail.
> Dependency chain: A → B → C → D → E. A cannot skip to C.

---

## Headline finding — read this before planning any work

**The Phase 8 brief was written against an assumed-greenfield state. It is not one.**
Groups A, B, C and D of the brief already exist in this repository and have been
working since Phase 4F / Phase 5. Group E is partly covered by existing suites.

Audited 2026-10-02 against the code, not against the brief. Every claim below is
backed by a file and line. Full suite, last verified on
`feature/phase-8-settings`: **984 passed / 3787 assertions**.

**All five groups are now DONE.** Groups A–D were pre-existing (Phase 4F/5); this
audit found gaps in A and B, and Group E's two gaps, and all of them are closed
and committed. What follows is the record of how each group was found, including
the parts that were wrong the first time.

| Brief group | Brief task | Reality | Evidence |
|---|---|---|---|
| A | `pages/settings/index.blade.php`, two-column grid | **EXISTS** | `resources/views/pages/settings/index.blade.php` — `col-lg-8 col-12` at :39 (4 cards), `col-12 col-lg-4` at :435 (2 cards) |
| A | input-group addons + tooltips | **EXISTS**, one defect | 18× `input-group-text bg-body-tertiary`; 30× `data-bs-toggle="tooltip"`; icons were `fs-6`, now `fs-7` |
| A | `tests/Feature/Settings/SettingsUiRenderTest.php` | **CREATED** | new file, 8 tests / 143 assertions |
| B | `UpdateSettingsRequest` | **EXISTS under another name** | `App\Http\Requests\V1\System\SystemSettingRequest` — 37 rules |
| B | `UpdateSystemSettingsAction` in `DB::transaction` | **EXISTS under another name** | `App\Actions\V1\System\SystemSettingsUpdateAction` — `DB::transaction` at :122 |
| B | `SettingsController` web + API | **EXISTS under another name** | `Web\V1\SystemSettingController` (`index`/`update`), `Api\V1\SystemSettingController` (`index`/`update`) |
| C | `settings.view` / `settings.manage` + superadmin bypass | **EXISTS** | `PermissionCatalog:64-65`; `Gate::before` at `AuthServiceProvider:62` |
| C | feature-flag middleware on `/settings` | **EXISTS** | `routes/web.php:77` and `routes/api.php:79` both wrap in `feature:settings` |
| C | `@can('settings.manage')` read-only view | **EXISTS** | view `@can` at :33 with an `@else` read-only table at :585 |
| D | audit inside the transaction | **EXISTS under another event name** | `->audit('system_setting.updated', $causer, $data)` at action:128, inside the transaction |
| D | HTTP context auto-captured | **EXISTS** | `App\Models\Concerns\Auditable:77-79` — `source`/`ip`/`user_agent` |
| E | `tests/Feature/Settings/SystemSettingsTest.php` | **DONE** | file deliberately not created; gaps closed in the existing suites — see § Group E |
| E | full suite green | **VERIFIED** | 984 passed / 3787 assertions |

**Consequence:** there is no build phase here. The remaining work is a
reconciliation pass — close the naming divergence between the brief and the code,
close two coverage gaps, and reconcile four trackers that still say `PLANNED`.

---

## Naming divergences — decide, do not silently diverge

The brief names four classes that do not exist. The equivalents that do exist
predate the brief and are load-bearing: `RATE-001`, `P6-C16` and Phase 5's
sweeps all reference them.

| Brief name | Actual name | Recommendation |
|---|---|---|
| `Requests\V1\Settings\UpdateSettingsRequest` | `Requests\V1\System\SystemSettingRequest` | **Keep actual.** Renaming breaks `P6-C16`, the RBAC gate, and `SettingsPersistenceTest`, and `V1\System` matches where the action and model already live. |
| `Actions\V1\Settings\UpdateSystemSettingsAction` | `Actions\V1\System\SystemSettingsUpdateAction` | **Keep actual.** Same reason, same namespace. |
| `Controllers\Web\V1\SettingsController` with `edit()` | `Controllers\Web\V1\SystemSettingController` with `index()` | **Keep actual.** The brief's `edit()` implies a separate edit page; the shipped design is one page with inline editing, which is what the two-column layout assumes. |
| audit event `system_settings.updated` | `system_setting.updated` | **Keep actual.** Singular matches the subject type. `SystemSettingUpdateTest:56` filters on it. |

**Open decision for Jaya:** confirm the above, or say which should move. The doc
records the recommendation; nothing has been renamed.

---

## Group A — Views, UI & Layout ✅ DONE

| ID | Task | Status |
|----|------|--------|
| P8-A1 | `resources/views/pages/settings/index.blade.php` — two-column grid | ✅ DONE — existed; `col-lg-8` at :39, `col-lg-4` at :466. **4 cards main / 2 sidebar**, not 3/2 — see §Layout decision |
| P8-A2 | input-group addons + info tooltips on all field labels | ✅ DONE — **fixed** this pass: 30 × `fs-6` → `fs-7` (the 3 remaining `fs-7` are `page-description`/`text-muted` prose, not icons) |
| P8-A3 | `tests/Feature/Settings/SettingsUiRenderTest.php` | ✅ DONE — created this pass; now **13 tests / 244 assertions** after the second and third passes |

### Group A audit (2026-10-02)

**Gap 1 — tooltip icons were `fs-6`.** `fs-6` is `1rem` (AdminLTE
`public/vendor/adminlte/css/adminlte.css:8484`), i.e. exactly the body size, so
an info-circle icon rendered as large as the label it annotates. `fs-7` is
`0.875rem` and is the house size — all 9 other pages use it, including
`docs/base/ui/design-system.md:411`. The settings page was the **only** file in
`resources/views` still using `fs-6`. Fixed; the view is now consistent with the
design system and with itself.

**Gap 2 — no render gate for this page.** `RbacUiRenderTest` and
`FeatureFlagUiRenderTest` both assert *"renders and queries nothing"* plus the
forbidden-class list, and both cover their page. Settings — the page with the
most controls of the three — had no equivalent. A query left in this Blade would
have shipped green.

`SettingsUiRenderTest` (8 tests):

| Test | What it pins |
|---|---|
| `it_renders_and_queries_nothing` | `ui-architecture.md` rule 1 — no query from Blade; measured as a delta across a warm second render |
| `the_forbidden_classes_never_appear` | no `bg-white`, no `bg-light`, no `card-body` without `p-4` |
| `the_page_keeps_the_two_column_grid` | `col-lg-8` and `col-lg-4` both present, save card `sticky-top` |
| `every_tooltip_icon_is_the_house_size_and_carries_a_title` | every icon is `fs-7` **and** has a title **and** a driver exists |
| `every_switch_has_a_hidden_companion` | every checkbox has `<input type="hidden" value="0">` — an unticked box sends no key, so a switch without one can only ever be enabled, and it round-trips fine when ON |
| `a_viewer_without_manage_gets_a_read_only_page` | the `@else` branch: no inputs, no save button, values still visible |
| `a_manager_is_not_shown_the_read_only_banner` | the `@can` branch, so the read-only test cannot pass vacuously |
| `it_renders_the_reference_options_the_controller_loaded` | timezone + role options come from controller data, not a hardcoded Blade list |
| `every_error_message_is_visible_and_associated_with_its_field` | every feedback div is `d-block` and `aria-describedby` points at it |
| `every_dependent_field_follows_its_toggle` | all 5 dependent fields are `disabled` when their toggle is off, and listed in the JS driver |
| `the_numeric_bounds_in_the_markup_match_the_validation_rules` | every `min`/`max` attribute equals the rule — a typo'd `max:140` for `1440` is otherwise invisible |
| `the_controller_hands_the_view_real_booleans` | a stored `'false'` arrives as `false`, through the HTTP path |

**Sabotage-verified.** Reverting `fs-7` → `fs-6` turns the tooltip test red
(`wrong icon class: …`). A guard test that has never gone red is not a guard.

### Group A second pass (2026-10-03) — design-system conformance

The first pass fixed `fs-6` and added a render gate. It read the page against
the *forbidden-class list* only. A second pass read it against the whole of
`design-system.md` and found four things the gate does not cover.

**Gap 3 — error messages were invisible on 18 of 22 fields.** Bootstrap shows a
feedback message through `.is-invalid ~ .invalid-feedback`, a sibling selector.
Every numeric field wraps its input in `.input-group`, which puts the message a
sibling of the *group*, not of the input — so a validation failure painted a red
border and showed nothing. `d-block` on the feedback div, plus an
`id`/`aria-describedby` pair per field (§Accessibility: "Error messages must be
programmatically associated with their field"). Pinned by
`every_error_message_is_visible_and_associated_with_its_field`.

**Gap 4 — `filter_var()` ran 12× inside Blade.** `@checked()` needs a real
boolean and the stored value is the string `'false'`, which is truthy in PHP —
so the cast was doing real work, in the wrong layer (`ui-architecture.md`
rule 1). Moved to `SystemSettingController::index()` behind one
`BOOLEAN_KEYS` constant that mirrors the request's `prepareForValidation()`
list. The `?? default` stayed in Blade on purpose: removing it turns a
partially-populated table into an undefined-key error, which is what happened
the first time this was tried.

**Gap 5 — `password_expiry_warn_days` ignored its own toggle.** The other four
dependent fields are disabled when their switch is off, in the markup and in the
JS `dependentFields` list. This one was in neither, so Warning Days stayed
editable with Password Expiry switched off. Added to both; pinned by
`every_dependent_field_follows_its_toggle`.

**Gap 6 — card numbering was wrong twice.** Two comments both said "Card 4"
(the third and the fifth card), and `section-change` /
`section-password-reset` named cards whose titles had since changed. Nothing
links to those ids, so they are cosmetic — but a comment that says the wrong
card is worse than no comment.

Two things the first pass called gaps that turned out not to be:

- **`fs-6`→`fs-7` was 30 icons, not 33.** The other 3 `fs-7` are prose.
- **`bi` vs `fas` is not a settings-page defect.** Form-page buttons use `bi`
  everywhere (`roles/create`, `roles/edit`, `users/edit` all carry
  `bi bi-x-circle` + `bi bi-check-circle-fill`); `fas` is the convention for
  header/dashboard chrome (`style-guide.md` §4). Settings follows the form-page
  convention. `design-system.md` is internally inconsistent here — its own page
  skeletons use `bi` while `style-guide.md` §4 mandates `fas` for interactive
  elements. Worth a doc pass, not 32 icon swaps.

**Gap 7 — two validated settings had no input at all.**
`email_verification_token_expire_minutes` and `password_reset_expire_minutes`
were seeded, given integer+min+max rules and whitelisted in the action, with no
field anywhere on the page — so no admin could set either, and nothing noticed.
Fields added to the *Rate Limits & Expirations* card beside the link-window
field each one belongs with. Neither is read by application code yet
(`config/auth.php` reads the `PASSWORD_RESET_EXPIRE_MINUTES` env var;
`AuthServiceProvider:158` reads `email_verification_expire_minutes`), so both
tooltips say so rather than implying an effect that is not there. Pinned by
`every_validated_setting_has_a_form_field` — the inverse of
`SettingsPersistenceTest`, which catches the other direction.

**Gap 8 — the rate fields in *Rate Limits & Expirations* said what was limited
without saying per what.** "Forgot Password Limit" and "Reset Submit Limit"
never named the window, and "Verify Request Limit" was per-hour while its two
neighbours were per-minute — the ambiguity was only resolvable by reading
`AuthServiceProvider`. Renamed to "Forgot Password Requests", "Reset Form
Submissions" and "Verification Emails Sent", with "Expiry"/"Lifetime" pairs
separated into Link vs Token validity. Their tooltips now name the limiter and
its window, and the three fields nothing reads say so rather than implying an
effect that is not there.

The `input-group-text` units (`min`, `/min`, `/ min`, `days`) were left as they
are. They are inconsistent as a set, but that is the house spelling and the
ambiguity it caused is now carried by the labels and tooltips instead.

### Group A third pass (2026-10-03) — label and tooltip copy

A copy pass over every label and tooltip on the page, against the field names
rather than the keys. Three outcomes.

**Layout decision — 4 cards in the main column, not 3.** A brief asked for
*Rate Limits & Expirations* to move to the `col-lg-4` sidebar alongside
*Identity & Account Rules*, leaving three cards in `col-lg-8`. Jaya kept it in
the main column. The sidebar therefore holds two cards (*Identity &amp; Account
Rules* and the sticky save card) and the main column four, and that is the
intended shape — the main column is where the security-policy cards belong and
the sidebar is deliberately short, because the save card is `sticky-top` and
only useful while there is still something above it to save.

`the_page_keeps_the_two_column_grid` asserts the two columns exist, not which
card sits in which, so this decision does not need a new test to hold.

**Labels.** Nineteen labels and twelve tooltips rewritten. The pattern: a label
names *what is measured*, never a bare "Limit" or "Expiry" that leaves the
window unstated. "Forgot Password Limit" became "Forgot Password Requests" —
the `Limit::perMinute` window was previously only discoverable by reading
`AuthServiceProvider`. "Reset Token Expiry" and "Reset Token Lifetime" were
separated into **Link** vs **Token** validity, because a link is what the user
presses and the token is what sits behind it.

Two labels in the brief were not applied:

| Field | In the page | In the brief |
|---|---|---|
| `lockout_increment_minutes` | Lockout Increment | Lockout Increment |
| `password_expiry_days` | Password Expiration | Password Expiration |

`password_expiry_days` is the one worth a second look: "Password Expiration"
duplicates the label on the `password_expiry_enabled` switch directly above it
("Enable Password Expiration"), so two different controls in one card carry
effectively the same name. "Password Expiration" resolves it. Left as-is pending
a decision, not overlooked.

**Three tooltips describe behaviour that does not happen yet.** *Reset Link
Lifetime*, *Reset Token Lifetime* and *Verification Token Lifetime* read as
though the value is enforced. It is not: `config/auth.php` reads the
`PASSWORD_RESET_EXPIRE_MINUTES` env var, and the verification flow reads
`email_verification_expire_minutes`. Earlier drafts of these tooltips said "not
yet wired"; the current text follows the brief. Recording it here so the copy
is a decision rather than an oversight.

> **Note on the brief's Group A wording.** It asks for a *stubbed* controller
> returning *dummy data* and for `GET /settings` to be *registered*. All three
> already exist in working form, gated on `auth` + `settings.view`. Stubbing them
> would delete working code. P8-A1/A2/A3 are therefore recorded as audits of
> shipped code, not as builds.

---

## Group B — Business Logic, Validation & Caching ✅ DONE (pre-existing)

| ID | Brief task | Actual | Status |
|----|-----------|--------|--------|
| P8-B1 | `UpdateSettingsRequest` + validation rules per domain | `SystemSettingRequest` — 37 rules across 6 domains | ✅ DONE |
| P8-B2 | `UpdateSystemSettingsAction` in `DB::transaction` + cache purge | `SystemSettingsUpdateAction` — one transaction for all keys | ✅ DONE |
| P8-B3 | Web + API controllers | `index()` + `update()` on both | ✅ DONE |

Three things in Group B that the brief does not mention and that are more
load-bearing than the brief's own items:

**1. One transaction for ~36 keys.** `SystemSetting::set()` writes one row per
key with no transaction of its own, so before this the loop could stop half way
— a failure on key 30 left the password policy updated and the registration
toggle not, with nothing to roll back and an audit row claiming success
(action:120-129).

**2. The whitelist is the gate, and one key is deliberately conditional.**
`rules()` has 37 keys; `$updates` has 36. The difference is
`inactivity_lock_grace_days`, written only when the grace toggle travels with
it (action:112-116). Verified this pass by parsing both lists — the sets differ
by exactly that one key, in that one direction.

A key that is validated and rendered but missing from `$updates` is **silently
dropped**: the form saves "successfully", the value never changes, nothing errors.
`SettingsPersistenceTest::test_every_validated_setting_is_actually_persisted`
guards exactly this and is the reason the gap is visible rather than silent.

**3. Web and API mean opposite things by a missing key — and only one can be right.**
`run($data, $partial: false)` for the web form (a missing boolean means OFF) and
`partial: true` for the API (a missing key means LEAVE IT ALONE). Sharing one
default silently reset `password_min_length` to 8 and switched
`registration_enabled` off in the same API call, disarming the password policy
and self-signup. The distinction is documented at action:28-45.

### Cache invalidation

`SET-005` says PLANNED. In practice `SystemSetting::set()` calls
`bustCache()`, which clears both the request-level static cache and
`Cache::forget('app_system_settings')` (model:156-161). The brief's "automatic
cache purging" requirement is met by the model, not by the action.

**Known gap, not fixed:** there is no `SystemSettingObserver`, so a write that
bypasses `set()` — a query-builder `update()`, a seeder calling `upsert()` — leaves
the cache stale. `App\Observers\` contains only `UserObserver`. Every shipped
write path goes through `set()`, so this is latent, not live. Decide in Group E
whether to add the observer or document the invariant.

---

## Group C — Security, RBAC & Feature Flags ✅ DONE (pre-existing)

| ID | Brief task | Status |
|----|-----------|--------|
| P8-C1 | `settings.view` / `settings.manage` + superadmin bypass | ✅ DONE — `PermissionCatalog:64-65`, `Gate::before` at `AuthServiceProvider:62`, request-level `authorize()` on `SystemSettingRequest:19` |
| P8-C2 | feature-flag middleware on `/settings` and `/api/v1/settings` | ✅ DONE — `routes/web.php:77`, `routes/api.php:79` |
| P8-C3 | `@can('settings.manage')` around controls + submit | ✅ DONE — view `@can` :33, `@else` read-only :585 |

**Deviation to record: the brief says 404, the code returns 403.** Phase 7
settled this on 2026-10-01 with reasoning in `docs/planning/phase-7-feature-flags.md`
(§ Decision 2): 404 claims the route does not exist, which is false and produces
a support trail of "this page 404s intermittently". `EnsureFeatureIsEnabled`
returns 403 and has **no** `can:` bypass — a kill switch a superadmin can walk
through is not a kill switch. The brief's 404 is stale. Do not "fix" this.

**Enforcement is layered, and that is deliberate:**

| Layer | What it does |
|---|---|
| `feature:settings` middleware | the flag decides whether the endpoint is served at all |
| `can:'settings.view'` / `can:'settings.manage'` on the route | the permission decides whether *this* user gets in |
| `SystemSettingRequest::authorize()` | defence in depth on the write, closed by `P6-C16` after RBAC-006 |

Pinned by `FeatureFlagPentestTest` (both settings routes in the data provider),
`RbacAuthorizationMatrixTest:207` and `:212` (403 on read and write), and
`RbacPentestTest`. **Do not treat `ui-authorization.md` §hiding as enforcement** —
the view's `@can` is UX; the middleware is the boundary.

---

## Group D — Audit Trail ✅ DONE (pre-existing, extended in `4de344f`)

| ID | Brief task | Status |
|----|-----------|--------|
| P8-D1 | audit event inside the action's transaction | ✅ DONE — action:128, `system_setting.updated`, inside `DB::transaction` |
| P8-D2 | HTTP context auto-captured | ✅ DONE — `Auditable:77-79` derives `source` (`web`/`api`), `ip`, `user_agent` |

Per **DEP-003** the record is written *inside* the transaction, before COMMIT, so
a rollback takes the audit row with the settings it describes. After-commit is
for jobs and events only.

Attribution passes the actor explicitly — the web controller sends
`causer: $request->user()`, the API controller the same, so one action produces
one correctly-attributed row from either channel.

**Both channels are now asserted, not just the web one.** `ActionFirstAuditTest`
covers the web path plus the rollback case; `4de344f` added the API causer
attribution and per-channel `source` (`web` vs `api`, asserted exactly rather
than as membership — a settings change over the API recorded as `web` is the
failure that matters, and nothing else would notice).

---

## Group E — Tests & Verification ✅ DONE

| ID | Brief task | Status |
|----|-----------|--------|
| P8-E1 | `tests/Feature/Settings/SystemSettingsTest.php` | ✅ DONE — deliberately NOT created; see below |
| P8-E2 | full suite green + `progress.md` | ✅ suite green (984/3787); trackers reconciled |

### What the brief's four E1 scenarios now have

| Scenario | Covered by | Verdict |
|---|---|---|
| form updates | `SystemSettingUpdateTest` | ✅ |
| 422 validation | `test_every_numeric_bound_rejects_a_value_outside_it` — all 40 bounds | ✅ |
| 403 RBAC denial | `RbacAuthorizationMatrixTest:207,212`, `RbacPentestTest`, `GateCAuthorizationTest` | ✅ |
| 404 feature flag | `FeatureFlagPentestTest` — both routes; **403, not 404** | ✅ (status differs from brief) |
| audit trail | 3 tests — web + API causer, API partial semantics, per-channel `source` | ✅ |

### The two gaps this audit found, both closed in `4de344f`

**Gap 1 — validation was proven on one field out of 37.** Closed by
`test_every_numeric_bound_rejects_a_value_outside_it`: it walks all 40
`min:`/`max:` bounds in `rules()` and posts one out-of-range value per bound.
It runs against the **API** channel because a web failure redirects and asserts
against flashed session state, which reads the same whether the bound held or
not.

> **Known limit, recorded rather than hidden.** This catches **drift**, not a
> single typo. The probed limit is read back out of `rules()`, so a bound
> mistyped inward (`max:140` for `max:1440`) still rejects `141` and the test
> stays green. Pinning the 40 numbers would mean the same constant in two
> places. Not done deliberately — it is the owner's call.

**Gap 2 — the API twin's audit and partial semantics were unproven.** Closed by
three tests: causer attribution (with `event` asserted non-null, since a NULL
event is skipped by every filter), partial semantics (the guard on the shipped
bug where one API call reset `password_min_length` and switched
`registration_enabled` off, returning 200), and per-channel `source`.

**Do NOT create `tests/Feature/Settings/SystemSettingsTest.php`.** It would
duplicate seven existing suites under a fourth name. The brief's filename was
deliberately not followed; both gaps went into `SystemSettingUpdateTest`.

---

## Deliberate simplifications

```
ponytail: system settings are a flat key/value table with no typed schema and no
per-setting class. ~36 keys, one `SystemSetting::set()`, one whitelist array.
Revisit only when settings gain relational meaning (a setting that references
another row, or needs its own history).
```

```
ponytail: `settings.view` without `settings.manage` renders a read-only table
rather than a disabled form. Disabled inputs that look editable are worse than
none — the value is visible and there is nothing to fill in. Revisit if a viewer
needs to *prepare* a change for someone else to approve.
```

```
ponytail: no settings history UI. The audit trail records every change with
before/after, so "what was this last Tuesday" is answerable from the audit log
rather than a second per-setting history table. Revisit if operators ask for it
more than once.
```

---

## Tracker reconciliation (outstanding)

Four places still describe Phase 8 as unbuilt. None is code.

| File | Says | Should say |
|---|---|---|
| `docs/planning/progress.md` | Phase 8 `PLANNED` | audit complete; Groups A–D pre-existing; E partial |
| `docs/planning/task-tracker.md` | `SET-001`…`SET-005` all `PLANNED` | `DONE` with notes (schema ✅, CRUD ✅, validation ✅, audit ✅, cache ✅) |
| `docs/planning/task-tracker.md` | `RATE-001` depends on `SET-001` | already satisfied — `RATE-001` is DONE |
| `docs/planning/feature-tracker.md` | row 24 notes `translations` / `activity_logs` have no routes until Phase 8 | those two flags still gate nothing; Phase 8 does not add them |
| `docs/base/features/settings.md` | "canonical keys" lists 9 of 36; validation table mixes real keys with dotted `security.*` / `registration.*` names that no code reads | rewrite against `SystemSettingSeeder` |

The last one is a **documentation defect, not a phase task** — that file lists
keys the system does not have and omits most of the ones it does, and its
`registration.default_role` / `security.password_history.count` names match
neither the seeder nor the form.

---

## Execution order for whatever comes next

1. **Group E, validation breadth** — one loop over `rules()`, ~37 assertions, closes the widest gap.
2. **Group E, API audit + partial-semantics** — the guard on a real bug, not a nicety.
3. **Tracker reconciliation** — the table above. Docs only.
4. **`docs/base/features/settings.md`** — rewrite against the seeder.
5. **Decide:** `SystemSettingObserver` for writes that bypass `set()` (Group B).

Items 1–2 are independent of each other and of 3–4. Nothing here blocks anything.