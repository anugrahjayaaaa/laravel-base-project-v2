# AdminLTE Setup

## Overview

AdminLTE is the initial/default web UI template for the Base Project. It is
**replaceable** — the Base Project architecture remains UI-independent
(ADR-002).

## Installation Strategy

AdminLTE must **NOT** be installed through npm.

### Approach

1. Download the selected AdminLTE release ZIP from the
   [official releases page](https://github.com/ColorlibHQ/AdminLTE/releases).
2. Extract the required frontend assets (CSS, JS, images, fonts) into:

   ```
   public/vendor/adminlte/
   ```

3. Keep AdminLTE assets isolated from application-specific assets in
   `public/assets/`.
4. Do **not** add AdminLTE to `package.json`.
5. Do **not** run `npm install admin-lte`.
6. Do **not** introduce an npm/Vite build requirement just to manage AdminLTE.

### Why ZIP / Vendored Assets

- The Base Project already ships `package.json` + Vite for its own minimal
  asset pipeline. AdminLTE is a full CSS/JS framework with its own release
  artifacts. Pulling it via npm would couple the application's build pipeline
  to AdminLTE's release cycle and bloat the dependency tree.
- AdminLTE's official release ZIP is a self-contained artifact that can be
  vendored into `public/vendor/adminlte/` with no build step.
- Switching or removing the UI template later requires only deleting the
  vendored directory and the Blade layout — no application code changes.

## AdminLTE Version

| Field | Value |
|-------|-------|
| Version | AdminLTE v4.x (exact release tag recorded at install time) |
| Asset location | `public/vendor/adminlte/` |
| Package.json | NOT added |
| npm | NOT used |
| ADR | ADR-019: AdminLTE as replaceable UI template |
| Task | UI-001 |

## UI Independence

The UI layer (Blade + AdminLTE + Bootstrap) is isolated behind layout
templates. Business logic lives in Actions/Services. Swapping AdminLTE for
Vue, React, or a mobile app only changes the UI layer.

See ADR-002 for the full architectural decision.

## ADR References

- ADR-002: UI-independent core
- ADR-019: AdminLTE as replaceable UI template