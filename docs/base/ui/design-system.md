# Design System

> Semantic design tokens only. No brand-specific color values are prescribed
> here — the visual identity is deployment-specific. The UI can be re-skinned
> without changing component structure.

## Design Tokens

Use semantic tokens (not raw hex values) in all UI components. This allows
the UI to migrate from Blade/AdminLTE to Vue/React without restyling every
component.

### Color Tokens

| Token | Purpose |
|-------|---------|
| `primary` | Primary brand/action color |
| `secondary` | Secondary action color |
| `success` | Success/state-positive color |
| `warning` | Warning/caution color |
| `danger` | Error/destructive color |
| `info` | Informational color |
| `surface` | Background surface |
| `surface-alt` | Alternate background (cards, etc.) |
| `text` | Primary text color |
| `text-muted` | Secondary/de-emphasized text |
| `border` | Border/divider color |
| `overlay` | Modal/overlay background |

### Typography Tokens

| Token | Purpose |
|-------|---------|
| `font-family-base` | Base font family |
| `font-size-base` | Base font size |
| `font-size-sm` | Small text |
| `font-size-lg` | Large text |
| `font-weight-normal` | Normal weight |
| `font-weight-medium` | Medium weight |
| `font-weight-bold` | Bold weight |

### Spacing Tokens

| Token | Purpose |
|-------|---------|
| `spacing-xs` | Extra small gap |
| `spacing-sm` | Small gap |
| `spacing-md` | Medium gap (standard) |
| `spacing-lg` | Large gap |
| `spacing-xl` | Extra large gap |

### Border Tokens

| Token | Purpose |
|-------|---------|
| `border-radius-sm` | Small border radius |
| `border-radius-md` | Medium border radius (standard) |
| `border-radius-lg` | Large border radius |
| `border-width-thin` | Thin border |
| `border-width-thick` | Thick border |

## Components

### Buttons

| Variant | Token |
|---------|-------|
| Primary | `primary` background, white text |
| Secondary | `secondary` background, white text |
| Success | `success` background, white text |
| Warning | `warning` background, dark text |
| Danger | `danger` background, white text |
| Outline | transparent background, token-colored border + text |
| Ghost | transparent background, token-colored text (on hover) |

### Forms

| Element | Token |
|---------|-------|
| Input border | `border` |
| Input focus | `primary` |
| Input error | `danger` |
| Label | `text` |
| Help text | `text-muted` |
| Error message | `danger` |

### Tables

| Element | Token |
|---------|-------|
| Header background | `surface-alt` |
| Row border | `border` |
| Row hover | `surface-alt` |
|| Status badge | `success`/`warning`/`danger`/`info` |

### Table Conventions

Shared table conventions:
- Columns sortable where meaningful (consistent UI pattern)
- Bulk selection supported where the list has per-row state actions. A list of
  read-only rows (a permission catalogue, a status view) gets no checkbox column —
  a selection affordance with nothing to select against is dead UI.
- Bulk actions exposed consistently — see §Bulk Actions
- Pagination follows the shared convention — see §Pagination (structure + tokens)
- Search/filter controls follow shared UI convention

### Table Actions Column

Action buttons in tables:
- Wrapper: `d-flex align-items-center justify-content-end gap-1 flex-wrap flex-md-nowrap`
- Button size: `btn-sm` or `px-2 py-1` for compact fit
- Wrap to vertical stack on mobile (`flex-wrap`), horizontal on desktop (`flex-md-nowrap`)

### Bulk Actions

One bar shape for every list. `#bulkBar` lives in the table `card-header`, stays
`d-none` until the first checkbox is ticked, and holds the count, the action
select, and the submit button. The shared driver reads the six attributes below —
one driver, one markup shape, per-list values only.

```html
<div id="bulkBar" class="d-none align-items-center gap-2 flex-wrap ms-auto"
    data-bulk-route="{{ route('roles.bulk-action') }}" data-bulk-field="role_ids[]"
    data-bulk-noun="role" data-bulk-mixed="delete"
    data-bulk-states='@json($trashed ? ['trashed' => ['restore', 'force_delete']] : ['active' => ['delete']])'
    data-bulk-keys='@json(['delete' => 'delete_role', 'restore' => 'restore_role', 'force_delete' => 'force_delete_role'])'>
    <span class="text-muted fs-7">Selected: <strong id="bulkCount">0</strong></span>
    <select id="bulkAction" class="form-select form-select-sm d-inline-block" style="width:auto">...</select>
    <button type="submit" class="btn btn-sm btn-primary">Apply</button>
</div>
```

| Attribute | Carries | Rule |
|---|---|---|
| `data-bulk-route` | POST endpoint for the bar | Always a `bulk-action` route; one per entity |
| `data-bulk-field` | the repeated id field name (`role_ids[]`) | Must match what the request validates — a mismatch posts ids the server never reads, and the bar reports success having changed nothing |
| `data-bulk-noun` | singular noun, for `N selected role(s)` | Singular; the count is interpolated by the driver |
| `data-bulk-states` | which actions exist per row state | `['active' => [...], 'trashed' => [...]]`. A row's state decides its action set, so one bar serves both the active and trash tabs |
| `data-bulk-mixed` | the action offered when the selection spans states | See below |
| `data-bulk-keys` | action → `ACTION_CONFIG` key map | See below |

**`data-bulk-mixed` — a mixed selection gets only the actions safe for every
selected row.** With 3 active and 2 trashed rows selected, `force_delete` is
not offered: it is legal for the trashed pair and destructive for the active
three. The bar falls back to the action named in `data-bulk-mixed` only when it
is valid for all of them — and if it is not, the bar offers nothing rather than
guessing. Whitelisting the intersection is the whole rule; an action that appears
because the first selected row happened to allow it will be applied to rows that
did not.

**Bulk copy comes from `ACTION_CONFIG` via `data-bulk-keys`, never from raw
`actionOptions`.** The map is `action => ACTION_CONFIG key`, e.g.
`delete => delete_role`. The driver looks the key up for `title`/`msg`/`variant`,
the same way a single-action trigger resolves through `resolveAction()` — so a
bulk bar and a row button describing the same operation cannot drift apart.
`actionOptions`/`actionLabels` carry only the option's own text.

Reference implementations: `resources/views/pages/roles/index.blade.php:130`,
`resources/views/pages/users/index.blade.php`.

### Badges

| Variant | Token |
|---------|-------|
| Success | `success` |
| Warning | `warning` |
| Danger | `danger` |
| Info | `info` |
| Neutral | `text-muted` |

### Alerts

| Variant | Token |
|---------|-------|
| Success | `success` surface, `success` icon |
| Warning | `warning` surface, `warning` icon |
| Danger | `danger` surface, `danger` icon |
| Info | `info` surface, `info` icon |

### Cards

| Element | Token |
|---------|-------|
| Background | `surface` |
| Border | `border` |
| Header background | `surface-alt` |

### Modals

| Element | Token |
|---------|-------|
|| Overlay | `overlay` |
|| Dialog background | `surface` |
|| Border | `border` |
|| Dialog header | `surface-alt` |

### Confirmation Modal Convention

One reusable confirmation-modal component with configurable variants
(danger/warning/info). Actions requiring confirmation: soft delete, permanent
delete, restore, lock, unlock, activate, deactivate, feature flag changes,
resend password, resend verification email, and other sensitive/destructive
actions. Do not create separate modal implementations per feature.

See [UI Architecture](./ui-architecture.md) § Confirmation Modal.

#### Modal Action Format

Every modal action reads from `ACTION_CONFIG` — single source of truth.

| Field | Example | Rule |
|-------|---------|------|
| **title** | `Move to Trash` | Static, imperative, short |
| **msg** | `Move <b>__ITEM__</b> to trash? They can be restored later.` | Template, `__ITEM__` → `<b>bold</b>` |
| **variant** | `danger` | Must follow [Action Color Convention](#action-color-convention) |

`__ITEM__` replacement: single → `data-item-name` (HTML-escaped), bulk → `N selected user(s)`.

Trigger attributes: `data-action-type` (key), `data-item-name`, `data-action` (URL), `data-method`.
Fallback: no `data-action-type` → JS uses `data-title`/`data-message` (legacy).

Write the trigger with the component, never as a hand-built attribute string:

```blade
<x-ui.confirm-action :action="route('users.destroy', $user)" method="DELETE"
    action-type="delete" :item-name="$user->name" label="Delete" />
```

The component emits the same `<button>` either way, so a page that hand-builds
one still works — which is why the drift is silent. A misspelled `data-*` simply
does not match, and the modal falls back to "Confirm" in danger red with nothing
in the log. `ConfirmActionUsageTest` fails on a hand-built trigger and on an
`action-type` that `ACTION_CONFIG` has no key for.

**Look copy up with `resolveAction()`, never `ACTION_CONFIG[key] || ...`.** A
fallback to `ACTION_CONFIG.delete` puts "Move X to trash?" on a Lock button for
any key the config does not have. `resolveAction()` returns `null` and warns
with the list of known keys, so a typo is visible in the console while
developing, and the caller picks its own generic copy. Both drivers use it.

**`action-type` defaults to `null`, and that is load-bearing.** The driver reads
`data-action-type` and looks it up in `ACTION_CONFIG`, so a component given a
non-null default would let a trigger that omits the attribute claim somebody
else's copy — the Unlock button on the user form would offer "Move X to trash?".
Omitting it is the honest signal that a trigger brings its own
`title`/`message`/`variant`/`icon` instead.

> **Status: adopted.** The `layouts/partials/modals/confirmation` modal is
> included once by the app layout, and all nine triggers across
> `pages/users/index`, `pages/users/edit`, and `pages/sessions` go through
> `<x-ui.confirm-action>`. The user-form Unlock keeps its own copy via the
> legacy branch. Seven of the other eight components are still unused — see
> VIEW-010 in the remediation tracker.

### Navigation

| Element | Token |
|---------|-------|
| Sidebar background | `surface` |
| Nav item active | `primary` |
| Nav item hover | `surface-alt` |
| Breadcrumb separator | `text-muted` |

### Pagination

The one footer for every index page, inside `card-body p-4` after
`.table-responsive`.

```html
<!-- Pagination -->
<div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
    @if ($items->total() > 0)
        <small class="text-muted">
            Showing {{ $items->firstItem() }} to {{ $items->lastItem() }} of {{ $items->total() }} entries
        </small>
    @endif
    <div class="d-flex">
        {{ $items->links() }}
    </div>
</div>
```

| Rule | Value | Why |
|------|-------|-----|
| Per page | `10` | `paginate(10)` — one shared value, not a per-page decision |
| Controller | `->paginate(10)->withQueryString()` | **required** — without it page 2 silently drops the active filter and sort, and the user lands on a different list than the one they were reading |
| Empty result | Render the count block only when `total() > 0` | `firstItem()` is null on an empty page; "Showing  to  of 0" is the tell |
| Wrapper | `justify-content-between`, `flex-wrap gap-2` | Count left, controls right; wraps to a stack on mobile instead of overflowing |
| Row numbering | `(currentPage() - 1) * perPage() + loop->iteration` | Plain `$loop->iteration` restarts at 1 on every page, so page 2 numbers its rows 1..10 again |
| Placement | After the table, inside `card-body` | Never in `card-footer` — that is reserved for form actions |
| Active page | `primary` | |
| Hover page | `surface-alt` | |
| Border | `border` | |

Bootstrap 5's default `links()` view is used as-is. Do not build a custom
paginator or swap the view per page: the block above is the whole convention.

Reference implementations: `resources/views/pages/users/index.blade.php`,
`pages/roles/index.blade.php`, `pages/permissions/index.blade.php`.

### Navigation Tabs (Status Filter)

Shared nav-pills conventions for index page status filters:
- Container: `nav nav-pills flex-nowrap overflow-auto` — horizontal scroll on mobile
- Link passive: `nav-link text-secondary fw-medium px-3 py-2 d-flex align-items-center gap-2 border-0 bg-transparent`
- Link active: `nav-link active fw-semibold text-primary px-3 py-2 d-flex align-items-center gap-2 border-0 border-bottom border-primary border-2 bg-transparent`
- Counter badge passive: `badge rounded-pill bg-secondary-subtle text-secondary`
- Counter badge active: `badge rounded-pill bg-primary text-white`

### Action Color Convention

Semantic color mapping for confirmation modal variants — MUST be consistent across single and bulk actions:

| Variant | Color | Actions |
|---------|-------|---------|
| `success` | hijau | activate, unlock, restore, **enable** |
| `warning` | orange | deactivate, lock, **disable** |
| `danger` | merah | delete, permanent delete |

**`disable` is `warning`, not `danger`.** Disabling a feature pauses access; it
destroys nothing. The rows behind it stay intact and the switch comes back with
one click, which is exactly what `danger` (delete, permanent delete) tells the
reader is not true. Painting a kill switch red teaches users to dismiss red
dialogs — and the one dialog that genuinely cannot be undone stops being read.
Reversible consequences never wear the irreversible colour.

Feature-flag toggles follow this: `enable => success`, `disable => warning`, in
both the row switch and the bulk bar.

Single action buttons: `data-variant` MUST match semantic mapping. Button class: `btn-outline-*` for outline style.

Bulk action: `variant` in `actionOptions`/`actionLabels` MUST match semantic mapping.

### Staged Changes

**Not used.** A control must not change visible state before the change is
stored. The pattern — toggle the switch, mutate the DOM, then POST — was
rejected in Phase 7: the failure modes are all invisible.

An optimistic toggle that the server then refuses leaves a switch claiming a
state the database does not hold, and the page has no way to know which of the
two is the truth. A toggle that fails mid-flight leaves it visually on, so the
user retries — twice — against a flag that was never activated. Neither is
recoverable by reloading, because the reload renders the *stored* value, which
contradicts what they just watched. The fix for each case is the same: don't
show a state you have not stored.

Any control that wants this behaviour must first write explicit unsaved/dirty
state guidelines into this section — what the optimistic rendering is, how
failure rolls back, what the user's next action is, and where the authoritative
value is shown while it is unresolved. Absent those, the answer stays no.

**Feature flags rely on §Bulk Actions and row-level instant toggles.** A flag
change goes through the confirmation modal (§Confirmation Modal Convention) and
submits, so the switch the user sees is always the switch the server rendered.
A flag list with per-row actions gets §Bulk Actions on top of that, never a
staged control underneath it.

## Consistency Rules

- Use the same token set across all UI implementations (Blade, Vue, React).
- Do not hardcode hex values in components — always use semantic tokens.
- When migrating UI technology, only the token-to-CSS mapping changes;
  component structure and markup stay the same.

## States

| Component State | Visual Treatment |
|-----------------|------------------|
| Default | Standard token styling |
| Hover | Slight background/lightness shift (`surface-alt` or opacity) |
| Active/focus | `primary` or `border` focus ring |
| Disabled | Reduced opacity (typically 50%) |
| Error/Invalid | `danger` border + error message |
| Loading | Spinner or skeleton (using `surface-alt` placeholder) |
| Empty | Centered message, muted text |
| Success | `success` color + icon |

## Responsive Behavior

- Mobile: sidebar hidden with open button; content full-width.
- Tablet: sidebar collapsible.
- Desktop: sidebar persistent.
- Breakpoints are defined by the CSS framework (Bootstrap 5.3 grid).

## Accessibility Basics

- All interactive elements must have accessible labels.
- Color is not the only indicator of state (use text/icons too).
- Focus states must be visible.
- Form inputs must have associated labels.
- Error messages must be programmatically associated with their field.

## ADR References

- ADR-002: UI-independent core
- ADR-001: API-first architecture

## Page Skeleton Templates

> Concrete HTML skeletons for all Admin pages. Every new view must match
> one of these exactly — no ad-hoc layout drift.

### 1. Content Header & Breadcrumbs

```html
<div class="content-header mb-3">
    <div class="d-flex justify-content-between align-items-start w-100">
        <div>
            <h1 class="page-title fw-bold">Page Title</h1>
            <p class="page-description text-muted fs-7 mb-0">
                <a href="..." class="text-muted text-decoration-none"><i class="fas fa-arrow-left me-1"></i>Back to Module List</a>
            </p>
        </div>
        <ol class="breadcrumb float-sm-end mb-0">
            <li class="breadcrumb-item"><a href="...">Module</a></li>
            <li class="breadcrumb-item active">Page</li>
        </ol>
    </div>
</div>
```

### 2. Index Page

```html
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <ul class="nav nav-pills gap-2 p-0 m-0">...</ul>
        <a href="..." class="btn btn-primary d-inline-flex align-items-center gap-2">
            <i class="bi bi-plus-lg"></i> Create
        </a>
    </div>
    <div class="card-body p-4">
        <!-- filters, table, pagination -->
    </div>
</div>
```

### 3. Create & Edit Form

```html
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-transparent border-bottom py-3">
        <h5 class="card-title mb-0 fw-semibold">Section Title</h5>
    </div>
    <form method="POST" action="...">
        @csrf
        <div class="card-body p-4">...</div>
        <div class="card-footer bg-body-tertiary border-top py-3 d-flex justify-content-end align-items-center gap-2">
            <a href="..." class="btn btn-outline-secondary d-inline-flex align-items-center gap-2">
                <i class="bi bi-x-circle"></i> Cancel
            </a>
            <button type="submit" class="btn btn-primary d-inline-flex align-items-center gap-2">
                <i class="bi bi-check-lg"></i> Save
            </button>
        </div>
    </form>
</div>
```

### 4. Modal (#confirmModal)

```html
<div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-bottom py-3">
                <h5 class="modal-title fw-semibold"><i class="bi bi-exclamation-triangle me-2"></i>Title</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">Message</div>
            <div class="modal-footer bg-body-tertiary border-top py-2 d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmAction">Confirm</button>
            </div>
        </div>
    </div>
</div>
```

### 5. Form Inputs & Validation

```html
<div class="mb-3">
    <label for="field" class="form-label">Label</label>
    <input type="text" name="field" id="field"
        class="form-control form-control-sm @error('field') is-invalid @enderror"
        value="{{ old('field') }}">
    @error('field')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>
```

### Forbidden Classes

| Class | Replace With |
|---|---|
| `bg-white` | `bg-body-tertiary` / `bg-transparent` |
| `bg-light` | `bg-body-tertiary` |
| Standalone Back button | Back link inside content-header description |
| `card-body` without `p-4` | Always `card-body.p-4` |

## Related

- [UI Architecture](./ui-architecture.md)
- [UI Authorization Rule](./ui-authorization.md)