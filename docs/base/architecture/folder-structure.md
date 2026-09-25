# Folder Structure

## Directory Layout

```
app/
├── Actions/          # Application/business logic (use cases) — single-operation classes
│                       # Naming: VerbNoun (e.g. ChangePasswordAction). One public method.
│                       # Extract when non-trivial (>~10 lines) OR shared across ≥2 controllers.
│                       # Mutations log audit within the action itself.
├── Http/
│   ├── Controllers/
│   │   ├── Api/
│   │   │   └── V1/     # API V1 controllers
│   │   └── Web/         # Web controllers (optional UI)
│   ├── Requests/         # Form requests (validation + auth)
│   ├── Resources/        # API resources (serialization) — use only when non-trivial
│   └── Middleware/       # Cross-cutting request boundaries
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
Requests/Api/V1/
Requests/Api/V2/
Resources/Api/V1/
Resources/Api/V2/
```

Application/domain logic may be shared when behavior is identical.

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