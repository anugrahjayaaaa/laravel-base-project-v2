# Responsive Audit — Laravel Base Project

**Date:** 2026-09-24  
**Branch:** feature/general-fixes

---

## Summary

| Page | Viewport | Issue | Severity |
|------|----------|-------|----------|
| User Index | Mobile | Table lacks `.table-responsive` — horizontal overflow | HIGH |
| User Index | Mobile | Filter tabs overflow at 360px | MEDIUM |
| User Create | Mobile | Callout text too wide on 360px | LOW |
| User Edit | Mobile | `col-lg-8`/`col-lg-4` stack OK but no `col-12` explicit | LOW |
| Profile Edit | Mobile | Callout warning may overflow | LOW |
| System Settings | Mobile | Fixed (col-6 col-md-3 + col-12 col-lg-4) | — |
| Confirm Modal | Mobile | No `modal-dialog-centered`, icon fs-1 too large | MEDIUM |

---

## Task Breakdown

### User Index Page
- [ ] Add `.table-responsive` wrapper around `<table class="table table-hover mb-0">`
- [ ] Filter tabs: add `flex-nowrap overflow-auto` to nav-pills (already has inline style, verify works)
- [ ] Search input `max-width: 250px` too wide for 360px — reduce to `max-width: 100%`

### System Settings Page
- [x] Card 1: `col-6 col-md-3` for mobile-friendly grid
- [x] Sidebar: `col-12 col-lg-4` for stacking
- [ ] Save button: ensure full-width on mobile (`w-100` on small screens)

### User Create Page
- [ ] Callout: add `px-3` padding reduction on mobile

### User Edit Page
- [ ] Add `col-12` explicit to both columns for clean stacking
- [ ] Danger Zone buttons: ensure `btn-sm` is tappable (min 44px)

### Profile Edit Page
- [ ] Add `p-3 p-md-4` to card-body for responsive padding
- [ ] Callout: ensure not truncated on 360px width

### Confirm Modal
- [ ] Add `modal-dialog-centered` to `.modal-dialog`
- [ ] Icon `fs-1` → `fs-4 fs-md-1` for mobile scaling
- [ ] Footer buttons: `w-100` on mobile, flex column on sm

---

## Scoring

- Mobile readiness: 6/8 pages need fixes
- Critical issues: 1 (table overflow on User Index)
- Medium: 2 (filter tabs, modal)
- Low: 4
