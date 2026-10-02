# Laravel Base Project v2 — Documentation

Reusable, API-first, UI-independent Laravel application foundation.

## Structure

```
docs/
├── README.md                       ← this file
├── base/                           ← reusable foundation docs
│   ├── README.md
│   ├── architecture/
│   ├── api/
│   ├── data/
│   ├── dependencies/
│   ├── features/
│   ├── governance/
│   ├── infrastructure/
│   ├── operations/
│   ├── security/
│   ├── testing/
│   └── ui/
├── custom/                         ← project-specific docs
│   └── README.md
├── operations/                     ← runbooks
├── postman files/                  ← exported API collections
├── qa/
│   └── remediation-tracker.md      ← audit findings and their resolution
├── security-audit-report.md
├── user-management.md
└── planning/                       ← planning system
    ├── README.md
    ├── requirements.md
    ├── feature-matrix.md
    ├── feature-tracker.md
    ├── implementation-roadmap.md
    ├── task-tracker.md
    ├── dependency-map.md
    ├── qa-tracker.md
    ├── changelog.md
    ├── decisions.md
    ├── progress.md
    ├── ai-execution-guide.md
    └── phase-*.md                  ← one per phase, written as it lands
```

## Quick Links

- [Architecture Principles](./base/architecture/principles.md)
- [API Architecture](./base/api/api-architecture.md)
- [Security Baseline](./base/security/security-baseline.md)
- [Implementation Roadmap](./planning/implementation-roadmap.md)
- [Task Tracker](./planning/task-tracker.md)
- [AI Execution Guide](./planning/ai-execution-guide.md)
- [QA Remediation Tracker](./qa/remediation-tracker.md)
- [Feature Flags](./base/features/feature-flags.md)

## Conventions

**Audit writes belong to the action layer.** A controller calls an action and
writes no audit row itself — the mutation and its `activity_log` row share one
`DB::transaction`, so a rollback cannot leave one without the other. An action
that writes no row outside `activity_log` is not "event with no state change"
and does not need a transaction of its own. See the
[tracker](./qa/remediation-tracker.md) for the full rule and the exceptions.

**Security tests must be able to fail.** Each claim is mutation-verified: break
the defence, confirm the named test goes red. A test that has never been seen to
fail is documentation. Files must end in `Test.php` to be discovered at all —
this is worth checking, because a misnamed security file is silently absent from
the suite while the suite reports green.