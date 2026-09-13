# Custom Documentation

> Project-specific documentation goes here.

## Purpose

`docs/custom/` contains documentation for features, workflows, and configurations that are **specific to a particular project** built on top of the Laravel Base Project.

These documents must **never** modify the meaning of Base documentation.

## What Goes Here

- Project-specific feature descriptions
- Customer-specific workflows
- Project-specific configuration overrides
- Custom module documentation
- Project-specific deployment notes
- Project-specific security considerations

## What Stays in Base

- Architecture principles
- Security baseline
- API contracts
- Core feature behavior (auth, RBAC, audit, etc.)
- Testing strategy
- Infrastructure patterns

## Adding Custom Documentation

When a project extends the base:

1. Document the extension in `docs/custom/`.
2. Reference the base document it extends (e.g., "Extends: `docs/base/features/settings.md`").
3. Do not redefine base behavior — only describe what is added/changed.
4. Keep base docs untouched.

## Example Structure

```
docs/custom/
├── README.md              ← this file
├── project-features.md    ← custom feature descriptions
├── customer-workflow.md   ← customer-specific workflows
├── deployment.md          ← project-specific deployment notes
└── security-addendum.md   ← project-specific security considerations
```

## Relationship to Planning

Planning documents (`docs/planning/`) describe the base project's roadmap. Project-specific planning (custom feature roadmap, custom task tracker) would go here if needed.