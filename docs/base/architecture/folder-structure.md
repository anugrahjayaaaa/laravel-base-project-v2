# Folder Structure

## Directory Layout

```
app/
├── Actions/          # Application/business logic (use cases) — single-operation classes
│                       # Naming: VerbNoun (e.g. AuthChangePasswordAction). One public method.
│                       # Extract when non-trivial (>~10 lines) OR shared across ≥2 controllers.
│                       # Mutations log audit within the action itself.
├── Http/
│   ├── Controllers/
│   │   ├── Api/
│   │   │   └── V1/     # API V1 controllers
│   │   └── Web/         # Web controllers (optional UI)
│   ├── Requests/         # Form requests only — nothing else lives here
│   │   ├── BaseFormRequest.php   # shared validation-failure contract
│   │   └── V1/{Domain}/         # Requests/{Version}/{Domain}/{Class}Request.php
│   ├── Resources/        # API resources (serialization) — use only when non-trivial
│   └── Middleware/       # Cross-cutting request boundaries
├── Concerns/         # Traits shared across layers (App\Concerns)
├── Models/           # Persistence models
├── Policies/         # Authorization decisions
├── Events/           # Domain/application events
├── Listeners/        # Event reactions
├── Jobs/             # Asynchronous/background processing
├── Notifications/    # User notification abstraction
├── Observers/        # Model lifecycle (not primary audit)
├── Services/         # External service integrations + cohesive domain objects
│                       # Naming: NounService (e.g. HealthCheckService).
│                       # Use for multi-consumer orchestration or external integrations.
│                       # Do NOT create solely because a Services folder exists.
└── Support/          # Shared helpers, enums, value objects

bootstrap/
config/
database/
├── migrations/
├── seeders/
└── factories/
docs/
├── base/             # Foundation documentation
├── custom/           # Project-specific documentation
└── planning/         # Planning system
public/
resources/
└── views/           # Blade views (replaceable UI)
routes/
├── api.php           # API routes
└── web.php           # Web routes (if applicable)
storage/
tests/
├── Unit/
├── Feature/
├── Api/
└── Arch/              # Architecture compliance tests
```

## API Versioning

Code structured as:
```
Controllers/Api/V1/
Controllers/Api/V2/
Controllers/Web/V1/
Requests/V1/            # shared by Web and Api — no channel segment
Requests/V2/
Resources/Api/V1/
Resources/Api/V2/
```

Application/domain logic may be shared when behavior is identical.

**Why `Requests/` has no channel segment.** A Form Request is consumed by both
channels (`StoreRoleRequest` is used by `Web/V1/RoleController` and
`Api/V1/Role/RoleController`). Putting it under `Requests/Api/` would make the
path lie about its own contents. The channel segment appears only where output
is genuinely channel-specific: Resources serialize JSON for the API, and
Controllers have two separate sets of HTTP entry points.

## Where Shared Code Goes

A folder holds one kind of thing. When shared behaviour was needed by the Form
Requests it was placed inside `Requests/`, which produced `Requests/Concerns/`
and `Requests/Traits/` holding three traits across two folders — the same idea
filed twice, in the wrong place.

| Kind | Location | Example |
|------|----------|---------|
| Behaviour only an HTTP Request uses | `Http/Requests/V1/{Domain}/` | `CreateUserRequest` |
| Behaviour shared across layers | `Concerns/` | `AuthorizesBulkAction` |
| Behaviour only Actions use | `Actions/Concerns/` | `PersistsRole` |

So the test is not "what calls it" but "what is it tied to": a trait overriding
`authorize()` or `prepareForValidation()` is tied to Form Requests, but the fact
that only Requests call it does not make it part of Requests. Traits that could
serve an Action or a Controller belong in `app/Concerns/`.

Two naming rules fall out of this:

- One name for one idea. `Traits/` and `Concerns/` are the same word; pick
  `Concerns/`, which is what `Actions/`, `Jobs/` and `Models/` already use.
- No folder should carry a prefix-free copy of a pattern that lives elsewhere.
  Four `Concerns/` directories inside four layers is fine — they are scoped to
  that layer. `Http/Requests/Traits/` was neither.

## Blade / UI Structure

The AdminLTE UI layer uses shared layouts and partials — NOT duplicated per
feature:

```
resources/views/
├── layouts/                    # Shared layouts (e.g. adminlte.blade.php)
│   └── partials/
│       ├── adminlte/           # Header, sidebar, footer, theme toggle
│       └── modals/             # Reusable confirmation modal
├── components/                 # Reusable UI components (buttons, tables, etc.)
│   └── ui/
└── pages/                     # Feature-specific page views
```

- Layouts and partials must NOT be duplicated per feature.
- Shared scripts must live in `resources/js/` or `resources/js/shared/` — do
  not create duplicate script copies per feature.
- Feature-specific views inherit from the shared layout.