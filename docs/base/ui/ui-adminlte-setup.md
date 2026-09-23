# AdminLTE Setup

## Overview

AdminLTE is the initial/default web UI template for the Base Project. It is
**replaceable** — the Base Project architecture remains UI-independent
(ADR-002, ADR-019).

## Installation Strategy

AdminLTE must **NOT** be installed through npm.

### Approach

1. Download the selected AdminLTE release ZIP from the
   [official releases page](https://github.com/ColorlibHQ/AdminLTE/releases).
   - Release tag: `vX.Y.Z` (e.g. `v4.9.1`)
   - Download URL: `https://api.github.com/repos/ColorlibHQ/AdminLTE/releases/tags/vX.Y.Z`
   - **Pitfall:** the release zip filename is `admin-lte-vX.Y.Z.zip` (hyphen
     + `lte-`), NOT `adminlte-X.Y.Z.zip`. Verify the exact filename from the
     GitHub API response before downloading.
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
- Pulling AdminLTE via npm would introduce an unnecessary build dependency and
  couple the deployment pipeline to AdminLTE's release cycle.
- Switching or removing the UI template later requires only deleting the
  vendored directory and the Blade layout — no application code changes.

## AdminLTE Version

| Field | Value |
|-------|-------|
| Version | v4.9.1 |
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

## Asset Pipeline — Vite

Vite compiles custom application assets (JS + Tailwind CSS) into
`public/build/assets/` with content-based hashing for cache busting.

### When to Rebuild

**Any change to `resources/js/` or `resources/css/` requires `npm run build`**.
Without rebuilding, the browser continues to load the old cached file
(browser cache + Vite hash in filename).

```bash
npm run build   # generates public/build/assets/app-[hash].js
```

### What Vite Compiles

- `resources/js/app.js` → imports `confirmation-modal.js`, `bulk-actions.js`
- `resources/css/app.css` → Tailwind CSS + base styles

### AdminLTE vs Vite

AdminLTE assets are vendored statically (`public/vendor/adminlte/`) and
do NOT go through Vite. Vite only handles application-specific custom
JS/CSS.

Runtime assets under `public/vendor/adminlte/` **are tracked in Git**. AdminLTE
is a runtime static dependency — not an npm package and not restored by
`composer install` or `npm install`. Committing the vendored CSS/JS ensures a
fresh clone works immediately after dependency installation without a manual,
undocumented download step.

## ADR References

- ADR-002: UI-independent core
- ADR-019: AdminLTE as replaceable UI template