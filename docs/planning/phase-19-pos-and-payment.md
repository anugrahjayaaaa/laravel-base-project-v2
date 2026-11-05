# Phase 19 — Basic POS Engine & Omnichannel Payment Gateway

> Date: 2026-11-05 | Status: **PLANNED** (breakdown written 2026-11-05) except the
> voucher Group A slice, which shipped 2026-11-05 and sits in REVIEW (P19-A6) |
> Branch when scheduled: `feature/phase-19-pos-and-payment`
> Purpose: spend what Phase 18 models — a cashier POS engine that writes real money rows,
> deducts stock and earns points; an omnichannel gateway integration behind encrypted
> credentials; an idempotent webhook that settles a payment exactly once; a financial
> dashboard reading the ledger this phase creates; and **the voucher domain — moved here
> from Phase 18 by owner decision 2026-11-05: vouchers are their own module (own flag,
> own menu, own permissions), checked at payment time, belonging neither to membership
> nor to the gateway.**
> Dependency chain: **A → B → C → D → E** (strictly sequential, § 2.1).
> Hard predecessor: **Phase 18** — this phase calls `EarnMemberPointsAction`,
> `RedeemMemberPointsAction` and `ActivateMemberSubscriptionAction`,
> and populates the `reference_type`/`reference_id` morphs Phase 18 left unpopulated on
> purpose (its § 7). `ClaimVoucherAction`/`ValidateVoucherAction` are **this phase's
> own** (B6) since the voucher move. Phase 18 in REVIEW does not block starting Phase
> 19's Group A; it blocks B2.
> Detail authority: this document. `task-tracker.md` holds the summary rows only.
> Design authority: `docs/base/`. A change to a design decision goes through an ADR in
> `docs/planning/decisions.md` — and this phase needs one before `composer require` (D-9).

---

## 1. Scope

Twenty-two tasks in five groups (the brief's nineteen, plus the three voucher rows —
A6/B6/B7 — moved in from Phase 18 on 2026-11-05).

| Group | Subject | Nature |
|---|---|---|
| A | Cashier POS UI, gateway settings card, financial dashboard, voucher admin view, flag declaration, render gates | **Build** (UI only) |
| B | Migrations, models, shift + transaction Actions, ESC/POS print, voucher domain, controllers | **Build** |
| C | Gateway order creation, atomic webhook, encrypted credential writer, controllers | **Build** |
| D | Route authorization, flag enforcement, audit events | **Enforce + verify** |
| E | Transaction/webhook/financial tests, four gates, tracker reconciliation | **Verify + close** |

**Not in scope:**

- **A product master-data module.** See **D-4** — the schema has no products, no stock,
  no cost. This phase gets the smallest `products` table that lets a sale be rung up, not
  a purchasing/inventory system (no supplier, no purchase order, no stock take, no
  multi-warehouse).
- Refunds as a money movement back through the gateway. A void is a reversal row in our
  ledger (D-8); the gateway-side refund API is a separate decision once a real gateway is
  chosen.
- Online checkout, invoicing, a customer-facing payment page, subscription auto-charge
  (a card-on-file vault), or anything storing a PAN.
- Multi-Outlet / per-register device identity, and offline cashier mode (a queue that
  replays when the line drops). Each is its own phase.
- i18n (`AGENTS.md`: no `lang/` source exists — every string here is literal English).
- Real-hardware printer calibration and a live gateway sandbox round trip — recorded as
  deferred runtime evidence (§ 7), the Phase 14 precedent.

### Why this phase reuses almost everything it needs

| Need | Existing owner | What Phase 19 adds |
|---|---|---|
| Encrypted credentials in `SystemSetting` | `encrypt()` on write, `AppServiceProvider::storedSecret()` compare, `SystemSetting::SECRET_KEYS` redaction, blank-means-unchanged | three gateway keys following the `mail_password`/`storage_s3_secret` shape exactly |
| Runtime rebinding of config after commit | `AppServiceProvider::bindMailConfig()`, `bindStorageConfig()` | one `bindPaymentConfig()` |
| Outbound HTTP with timeout + retry | `Illuminate\Http\Client` — `timeout()` (`PendingRequest.php:644`), `retry($times, $sleep, $when)` (`:671`) | nothing; both are native |
| Circuit breaker | **not in the framework on this install** (D-3) | one small cache-backed class |
| Ungated public API route | `GET /api/v1/health` with `throttle:health` | the webhook, same shape |
| Atomic conditional state transition | the `unlock`/last-superadmin refusals, `JobConventionTest`-adjacent patterns | `UPDATE … WHERE status = 'pending'` + affected-rows check |
| Money-in / money-out audit | `Auditable::audit()` inside the transaction, `causer = 'SYSTEM'` for system rows | nine event names |
| Queued work with a named queue | `App\Support\QueueName`, `bin/run-workers.sh` | gateway calls on `exports`, sweeps on `sweeps` |

---

## 2. Specifications (owner decisions)

1. **Sequential execution.** A → B → C → D → E, one group open at a time, each task
   `IN_PROGRESS → REVIEW → DONE`, never skipping REVIEW.
2. **Group A is UI-only**, with the same forced carve-out as Phase 18 (D-2 in that doc):
   a menu entry must point at a route that exists, so route + sidebar registration ships
   with the controllers (B5 / C4), and A's render gate renders views from fixtures.
3. **A settled payment is never undone by a side effect.** Print, mail, and gateway
   notification all run **outside** the money transaction and their failure cannot roll it
   back. The DB transaction covers: stock, cash, transaction rows, points, audit.
4. **Idempotency is a constraint, not a lock.** Every webhook settles through an atomic
   conditional update plus a unique event key; `lockForUpdate()` is defence in depth on
   MySQL only (D-5).
5. **The reference we generate is the idempotency key the gateway honours.** `order_id` is
   ours, deterministic, and sent on every attempt — retrying a timed-out charge without it
   is how a customer is charged twice (D-3).
6. **Credentials never leave the secret path.** Not in a view value, not in
   `SystemSetting::getAll()`, not in the audit payload, not in the log line, not in a
   query string. `hash_equals()` for signature comparison — never `==`.
7. **Action-first, thin controllers, FormRequest validation, route-level `can:` +
   `feature:`** (`AGENTS.md`), one set of Actions behind both channels, and the web/API
   capability table diffed **by capability** before the phase closes (the Phase 12 method).
8. **Money is `decimal`, never `float`, never a PHP arithmetic accident.** Sums happen in
   SQL or in `bcmath`-free integer minor units; the schema stores `decimal(12,2)` and the
   tests use exact comparisons.
9. **No inline FQCN** (`NoInlineFqnTest`); **cache primitives only** (a Collection through
   the Redis store returns `__PHP_Incomplete_Class` on a hit — `AGENTS.md`); new code does
   not grow the 310-entry PHPStan ledger.
10. **A new Composer package stops the phase** until `docs/planning/changelog.md` + an ADR
    exist (`AGENTS.md`). This phase has exactly one candidate (D-9).
11. **Voucher is a domain, not a feature of its neighbours.** Since the owner move of
    2026-11-05 the voucher has its own flag (`vouchers`), its own menu entry, its own
    permissions (`vouchers.view`/`vouchers.manage`) and its own tables. POS and the
    gateway **consume** it only: a sale presents a code, `ValidateVoucherAction` answers
    valid-or-not plus the capped discount, `ClaimVoucherAction` records the usage — no
    screen outside `pages/vouchers/` edits voucher data.

---

## 3. Deviations from the brief (measured against the shipped code)

| # | The brief said | The repository does | Consequence |
|---|---|---|---|
| **D-1** | P19-D2: flag disabled → **HTTP 404** | **403** — settled in `EnsureFeatureIsEnabled` ("Why 403, not 404", 2026-10-01), recorded by Phase 12 as "not to be *fixed* into a bug", pinned by 62 tests, and re-asserted as P18 D-1 | D2 asserts 403 on both channels |
| **D-2** | P19-A1: "Fast-responsive screen (**Livewire/Alpine**)" | Neither is installed: `package.json` devDependencies are vite, laravel-vite-plugin, bootstrap, tailwindcss (D-8 of Phase 17), concurrently; `AGENTS.md` says no Vue/React; house JS is vanilla modules under `resources/js/` (`bulk-actions.js`, `password-strength.js`) rendered through `@vite` and `@nonce`d because CSP omits `'unsafe-inline'` (`ContentSecurityPolicyTest` pins it). Livewire's inline `wire:` behaviour and its payload round trips both fight that gate | The cashier screen is **one Blade page + a vanilla JS cart** posting to the JSON endpoints B5/C4 ship. No new frontend dependency, no CSP exception. Cart state lives in the browser; **the server recomputes every total** (D-7) |
| **D-3** | P19: "HTTP Client retry (3 retries, timeout 5s) **and circuit breaker pattern**" | `Http::timeout()` and `Http::retry()` are native (`PendingRequest.php:644`, `:671`) — zero code needed. **`Illuminate\Support\CircuitBreaker` does not exist in this install** (verified: no class and no matching file under `vendor/laravel/framework`) | Retry/timeout: use the framework. Breaker: one small cache-backed class (`App\Support\GatewayCircuitBreaker`, ~40 lines, open after N consecutive failures, half-open probe after a backoff, state in `Cache`) — the only genuinely new infrastructure this phase gets. **And retry is unsafe on a non-idempotent call**: retried only on connection/timeout errors, always with our deterministic `order_id`, and an open breaker must answer a distinct error code so the cashier falls back to cash rather than hanging |
| **D-4** | P19-B1: `stock_movements`, `transaction_items` … | **There is no product table.** `database/migrations` contains no product/inventory/stock/payment/transaction migration; `app/Models` has none. `transaction_items` has nothing to reference and stock has nothing to deduct **from** | B1 must add `products` (`sku` unique, `name`, `price decimal`, `cost decimal` nullable, `stock_qty`, `is_active`). **No product CRUD is implied** — see O-1: the owner decides how a product gets into the table (thin admin screen vs seeder vs import) before B1 is committed |
| **D-5** | P19-C2: `lockForUpdate()` is the race shield | `phpunit.xml` runs `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:` — `FOR UPDATE` parses and does nothing, so a test that relies on it is a false green (the same trap Phase 18 recorded, and the voucher race test now travels here with the domain) | The real shield is portable and is what E1 proves: an **atomic conditional update** (`WHERE id = ? AND status = 'pending'`, then assert affected rows = 1) **plus a unique index on the gateway event id**. `lockForUpdate()` stays in the Action as the MySQL half; the MySQL two-session probe is deferred runtime evidence |
| **D-6** | P19-A2 + C3: gateway keys in `SystemSetting` | `SystemSettingsUpdateAction` holds **one whitelist** of acceptable keys and `GET /api/v1/settings` answers from `SystemSetting::getAll()` minus `SECRET_KEYS`. Phase 9's first finding was that a credential reachable through the settings module (`settings.view`) is read by a wider audience than the one it was built for | The three keys are added to `SystemSetting::SECRET_KEYS` and **deliberately NOT** to the settings whitelist — so `PUT /settings` and `GET /api/v1/settings` can neither write nor read them. `UpdatePaymentSettingsAction` (C3) is the **only** writer, behind `payments.manage`. A `settings.manage` holder must not be able to repoint production money. Blank-means-unchanged and the plaintext compare come from `SystemSettingsUpdateAction:213-234` as shipped |
| **D-7** | P19-A3: "Include/Exclude Tax filter toggles" (UI-side) and A1's cart | Nothing in this codebase trusts a client-side number, and it must not start here: a tampered cart total is a revenue leak, not a UI bug | Prices, tax, discount and change are computed **server-side from `products` and `SystemSetting` rates**; the browser's figure is a preview only. `ProcessPosTransactionAction` re-derives every line and refuses a mismatch |
| **D-8** | P19-D1: `pos.void_transaction` | "Void" of a settled payment is a **money event**. The house deletion policy (`docs/base/data/soft-delete.md`) and the ledger convention Phase 18 used (`member_point_histories`) both say: never edit or delete the row that happened | A void inserts a reversing `pos_transactions` row (`kind = 'void'`, negative amounts, `void_of_id`), audited, and re-stock through `stock_movements`. Nothing is deleted, no status field flips a paid row to unpaid |
| **D-9** | P19-B4: `mike42/escpos-php` | `AGENTS.md`: a new Composer package needs `docs/planning/changelog.md` + an ADR **first**. The package needs `ext-mbstring`/`iconv`; using it for *images* pulls GD/ImageMagick, and no thermal printer exists in CI or in the test suite | ADR-031 before any `composer require`. Receipts use the printer's **native** QR/barcode commands (no GD). The print path is verified by asserting the **byte stream** (escpos renders to a buffer — that is testable); "the paper came out right on a 58 mm head" is deferred runtime evidence. Cash-drawer kick is one `Escpos` call, never a retry loop |
| **D-10** | P19-E: "**100 % test coverage**" | The enforced bars are global ≥ 85 %, Actions ≥ 95 %, Policies 100 %, Requests ≥ 95 % (`bin/coverage_gate.php`, bars assigned as P15 D-13). No 100 % bar exists and the phase must not lower or fake one | E1 states the bars and reports what the run measured. Coverage gaps are a named list, not an averaged claim |
| **D-11** | P19-A3: "**Net Profit**" card | Profit needs a cost, and nothing in the schema has one (D-4 — a product has a price). Gross revenue, gateway fees and tax are computable from `payment_logs` + `pos_transactions`; profit is not | Either `products.cost` is filled and maintained (a decision with a data-entry consequence nobody named), or the Profit card ships in a later phase. Recorded as O-2; A3 is written to render it only when cost data exists |
| **D-12** | (unstated) webhook = a public POST | CSRF runs on the `web` group only (`ValidateCsrfToken` replaced `PreventRequestForgery` **in that group**, P13-B), and its `$except` list is empty **and pinned** by `CsrfProtectionTest`. The gateway never sends a session cookie | **No `except` entry is needed** — an `api/v1` route is not in the `web` group. Do not "fix" the empty except list. The webhook does need: no `auth:sanctum`, its own named `RateLimiter::for()` bucket (15 exist today; `rate-limiting.md` owns the table), HMAC verification before any DB read, and a bounded payload |
| **D-13** | P19-B1: `transactions`, `transaction_items` | A table literally named `transactions` next to `payment_logs`, `member_subscriptions` and a future accounting import is the kind of generic name `naming-conventions.md` exists to prevent; the domain folder here is `Pos` | Tables are `pos_transactions` / `pos_transaction_items`; `stock_movements`, `pos_shifts`, `payment_logs` keep the brief's names. One `use` per domain in the `naming-conventions.md` domains table (the P18 D-7 rule) |

---

## 4. Data model

| Table | Key columns | Notes |
|---|---|---|
| `products` | `id`, `sku` (unique), `name`, `price decimal(12,2)`, `cost decimal(12,2)` nullable (O-2), `stock_qty` `integer` (nullable = not stock-tracked), `is_active` | Forced by D-4. `stock_qty` is the counter; `stock_movements` is the ledger. Locked with `lockForUpdate()` on the MySQL side, guarded by the conditional update elsewhere |
| `pos_shifts` | `id`, `opened_by` FK→`users`, `opened_at`, `closed_by` nullable, `closed_at` nullable, `opening_float decimal`, `expected_cash decimal`, `counted_cash decimal` nullable, `variance decimal` nullable, `status` enum(`open`,`closed`) | One open shift per cashier is enforced in `OpenPosShiftAction` (the DB half is a unique partial index, MySQL-only — stated, not assumed) |
| `pos_transactions` | `id`, `reference` (unique — **our** order id, D-3), `pos_shift_id` FK, `member_id` FK nullable, `kind` enum(`sale`,`void`), `void_of_id` self-FK nullable, `subtotal`, `discount_total`, `tax_total`, `total`, `paid_amount`, `change_due` `decimal(12,2)`, `payment_method` enum(`cash`,`qris`,`edc`,`gateway`), `status` enum(`pending`,`paid`,`failed`,`voided`), `settled_at` nullable, `currency` | Index `(status, settled_at)` and `(pos_shift_id)` — the two shapes the dashboard and the reconciliation read. Money `decimal`, never `float` (spec 8) |
| `pos_transaction_items` | `id`, `pos_transaction_id` FK, `product_id` FK, `quantity integer`, `unit_price`, `line_tax`, `line_total` `decimal(12,2)` | Snapshots the price at sale time — a later `products.price` edit must not rewrite history |
| `stock_movements` | `id`, `product_id` FK, `type` enum(`sale`,`void`,`adjustment`), `quantity` `integer` (signed), `reference_type`/`reference_id` morph | Ledger only. A correction is a new row (the `member_point_histories` rule) |
| `payment_logs` | `id`, `pos_transaction_id` FK nullable, `gateway`, `environment` enum(`sandbox`,`production`), `event_id` (unique together with `gateway`), `order_reference`, `status`, `amount decimal` nullable, `fee decimal` nullable, `payload json` (**redacted**, D-6/PCI note below), `received_at` | The unique `(gateway, event_id)` is the idempotency backstop D-5 names. `payload` must never hold a PAN or a full card object: store the gateway's masked fields and the raw signature **is not stored at all** |
| `vouchers` | `id`, `code` (unique, upper), `type` enum(`percentage`,`fixed`), `value` `decimal(12,2)`, `max_discount` `decimal(12,2)` nullable, `min_purchase` `decimal(12,2)` default 0, `quota` `unsignedInteger` nullable (null = unlimited), `claimed_count` `unsignedInteger` default 0, `per_member_limit` `unsignedTinyInteger` default 1, `starts_at`, `expires_at`, `is_active` | **Moved from Phase 18 § 4** with the domain. Money as `decimal`, never `float`. Here the columns finally have an order total to act on — `max_discount` caps the discount and `min_purchase` gates it (the Phase 18 O-4, closed by the move) |
| `voucher_usages` | `id`, `voucher_id` FK, `member_id` FK, `claimed_at`, `reference_type`/`reference_id` nullable morph | Unique `(voucher_id, member_id)` — the idempotency backstop the lock cannot be alone against (D-5). The morph is populated by the POS sale that consumed the code |

Settings live in `SystemSetting`, not in a table: `payment_gateway_driver`,
`payment_gateway_environment`, `payment_server_key`, `payment_client_key`,
`payment_webhook_secret` (the last three = `SECRET_KEYS`), `tax_ppn_rate`, `tax_pph_rate`,
`payment_log_retention_days`. Tax rates and retention **do** go through the shared settings
whitelist — they are configuration, not credentials (the distinction D-6 draws).

---

## 5. Tasks

### Group A — Cashier UI, gateway settings card, financial dashboard, voucher admin view, flag declaration

Exit gate: fixture render green, zero DB in Blade, all three flags (`pos`,
`payment_gateway`, `vouchers`) declared **and** seeded, Pint clean.

#### P19-A1 — Cashier POS interface
- **Task**: `resources/views/pages/pos/cashier.blade.php`. Product search box, cart
  table, member picker, payment-method selector (`cash` / `QRIS` / `EDC`), keyboard-shortcut
  legend, receipt preview modal. Vanilla JS cart module under `resources/js/pos/`
  (`@vite`-built, `@nonce`d) talking to the B5/C4 JSON endpoints. Design system: `card
  border-0 shadow-sm mb-4`, footer `bg-body-tertiary border-top py-3`, no `input-group`
  around a validated field.
- **Behaviour**: **the cart is a preview, never the invoice** (D-7) — the server recomputes
  subtotal, tax, discount and change. Keyboard shortcuts are real `<kbd>` labels bound in
  JS, not `onclick=` attributes (CSP).
- **Acceptance**: renders empty (no shift, no products, one line, twenty lines); a refused
  action (no open shift, insufficient stock) has a visible error state, not a silent no-op;
  the receipt preview modal shows the same fields the printer will emit.
- **Tests**: `PosUiRenderTest` (A5).

#### P19-A2 — Payment gateway configuration card
- **Task**: `resources/views/pages/payments/settings.blade.php`, `col-lg-8`: driver select,
  environment toggle (sandbox/production), the three credential inputs rendered **blank**
  with "leave blank to keep the stored value", and the webhook URL the operator must paste
  into the gateway dashboard (read-only, copied from `route()`).
- **Behaviour**: mirrors the mail/S3 cards — secrets are never populated from a read path,
  the environment switch warns before pointing at production, and nothing here computes.
- **Acceptance: no credential is in the rendered HTML** (asserted against the markup, not
  assumed); the webhook URL is the real signed-free absolute URL; a production toggle
  requires a confirmation step.
- **Tests**: `PaymentSettingsUiRenderTest` (A5).

#### P19-A3 — Financial analytics dashboard
- **Task**: `resources/views/pages/payments/dashboard.blade.php` — gross revenue, net
  revenue, gateway fees, PPN/PPH breakdown, and the tax include/exclude toggle as a
  query-string filter. Profit card **conditional** (D-11/O-2).
- **Behaviour**: every number is a primitive produced by the read Action; charts are
  server-rendered tables or the existing Pulse blocks, no new chart dependency.
- **Acceptance**: a range with zero rows renders zeros rather than an error; the toggle
  changes the filter, not the arithmetic; the page's query count is pinned (the
  `PerformanceGuardTest` shape — a list must not grow with row count).
- **Tests**: `PosDashboardRenderTest` (A5) + `FinancialDashboardTest` (E1).

#### P19-A4a — Feature-flag declaration & activation
- **Task**: `pos` and `payment_gateway` in `config/pennant.php` (label, group, description,
  and the setting keys each owns) — no `FeatureFlagSeeder` edit is needed: the seeder loops
  `FeatureCatalog::slugs()`, so a declared slug is seeded the day it appears (proved by
  P18-A4a).
  The third flag of this phase's trio, `vouchers` (group Sales), already shipped with the
  voucher move in **P19-A6** — the seeder is catalogue-driven, so both new slugs are seeded
  the day they are declared, no seeder edit.
- **Why two flags, not one**: `AGENTS.md` records the Phase 11 lesson — a route group that
  ANDs unrelated flags switches off the wrong module. A cashier printing cash receipts must
  keep working when the gateway is killed (that is the point of the kill switch), so
  `pos` and `payment_gateway` are independent and the QRIS path is the one that 403s.
- **Acceptance**: both resolve active after `db:seed`; the existing `FeatureFlag*` suites
  cover them by enumerating the catalogue (no new flag test file).
- **Docs**: `docs/base/features/feature-flags.md` gains two rows.

#### P19-A5 — UI render test suite
- **Task**: `tests/Feature/POS/PosUiRenderTest.php` (+ the two named in A2/A3) in the
  `FormViewRenderTest` shape: `view(...)->render()` on fixtures, a sweep asserting no POS
  view reaches for the database, and one assertion that no secret-shaped string appears in
  any rendered POS/payment view.
- **Acceptance**: passes with the app down for maintenance; each gate sabotage-verified
  individually before it is called a gate.

#### P19-A6 — Voucher admin view, flag & render gate — **SHIPPED 2026-11-05, in REVIEW**
Moved out of Phase 18 (its P18-A2/A4a/A5) by owner decision the same day: the voucher is
its own domain — checked at payment time, not part of membership and not part of the
gateway.
- **Shipped**: `resources/views/pages/vouchers/index.blade.php` — one editable row per
  voucher via per-row HTML5 `form=`-attribute forms (a `<form>` may not wrap `<tr>`);
  edits code, type, value, `max_discount`, `min_purchase`, quota (null renders an
  "Unlimited" placeholder, `claimed_count` is printed as handed, never a computed
  remaining), per-member limit, start/expiry window and the hidden-`0`/checkbox-`1`
  active pair; `@feature('vouchers')` wrapper and `@can('vouchers.manage')` on every Save.
- **Shipped**: `vouchers` declared in `config/pennant.php` (group Sales, label
  "Vouchers") — seeded by the catalogue-driven `FeatureFlagSeeder` with no seeder edit.
- **Shipped**: `tests/Feature/Voucher/VoucherUiRenderTest.php` — fixture render (routes
  arrive in B7 per the same D-4 carve-out Phase 18 used), quota-as-handed gate, staff
  loses every Save, flag-off empties the screen (sabotage-verified individually), and the
  no-DB/no-`route()`/no-inline-script sweep over the view source.
- **Still open here**: the menu entry and routes (B7) and the permissions (D1) — a Group
  A view with no route is the deliberate D-4 shape, not a gap.

### Group B — POS engine, shifts, stock, voucher domain, printing

Exit gate: migrate/rollback exit 0 on SQLite; Actions ≥ 95 % covered; `ActionNamingConventionTest`,
`NoInlineFqnTest`, `JobConventionTest` green.

#### P19-B1 — Migrations, models, factories
- **Task**: § 4 tables (including `products`, D-4) + `App\Models\{Product,PosShift,PosTransaction,PosTransactionItem,StockMovement,PaymentLog}`
  with `Auditable`, casts to `decimal:2` for money, factories for each. `PosTransaction`
  gets the `reference` unique. The two `voucher*` tables of § 4 and their models ship in
  **B6** — same group, split by domain (spec 11).
- **Acceptance**: `migrate` and `migrate:rollback` exit 0; a `void` row cannot exist without
  `void_of_id`; a sale with no shift is refused by the Action, not by the DB alone.
- **Tests**: `PosModelTest`.
- **Docs**: `docs/base/data/` gains the POS schema page; `naming-conventions.md` gains the
  `Pos` domain (D-13).

#### P19-B2 — `ProcessPosTransactionAction`
- **Task**: `app/Actions/V1/Pos/ProcessPosTransactionAction` — one `DB::transaction`:
  require an open shift → **recompute every line from `products`** (D-7) → if the cashier
  entered a code, validate it with `ValidateVoucherAction` (B6) and apply the capped
  discount, claiming it with `ClaimVoucherAction` (B6, populating the usage row's
  `reference_*` morph); an invalid code refuses the sale with the contract's error code —
  then any point redemption → `lockForUpdate()` + conditional stock decrement (refuse a negative) →
  insert the transaction + items → compute change → earn points via
  `EarnMemberPointsAction` → audit `pos_transaction.created`. Post-commit: dispatch the
  print job.
- **Acceptance**: an oversell is impossible (a second concurrent sale of the last unit is
  refused — proven by the conditional update, D-5); the shift's expected cash moves by
  exactly the change arithmetic; a stock refusal leaves no transaction row; the points
  ledger's `balance_after` still equals the member's balance.
- **Tests**: `PosTransactionTest` (E1).

#### P19-B3 — Shift open/close Actions
- **Task**: `OpenPosShiftAction` (one open shift per cashier, opening float validated) and
  `ClosePosShiftAction` (expected cash = opening float + cash sales − cash voids − change;
  `counted_cash` in, `variance` computed and audited).
- **Acceptance**: a second open on the same register is refused with a named reason;
  a close with an unsettled `pending` transaction states what happens to it (it is
  voided-by-hand, not silently swept) — decided and tested, not left implicit.
- **Tests**: `PosShiftTest`.

#### P19-B4 — ESC/POS receipt printing
- **Task**: **ADR-031 + a `changelog.md` line first** (D-9), then `mike42/escpos-php`
  behind `App\Support\Receipt\ReceiptPrinter`: receipt header from `config('app.name')`,
  itemised lines, tax lines, member + points footer, the QRIS payload as the **native** QR
  command, and one cash-drawer kick on a cash sale.
- **Behaviour**: called **after commit**, outside the money transaction; a printer failure
  is logged and surfaced as "receipt not printed — reprint available", never as a failed
  sale. Connector configured from `SystemSetting` (network / file / sandbox null driver),
  with a short socket timeout so a dead printer cannot hold a cashier's request open.
- **Acceptance**: the emitted **byte stream** is asserted for a known cart (that is what CI
  can prove); a null/sandbox driver renders without hardware; reprint of an existing
  transaction produces the same bytes.
- **Tests**: `ReceiptPrintTest` (byte-level) + `JobConventionTest`.
- **Deferred**: "the paper came out right" on real hardware — § 7.

#### P19-B5 — POS controllers, routes, sidebar
- **Task**: thin `app/Http/Controllers/Web/V1/{PosCashierController,PosTransactionController,PosShiftController}`
  + the `Api/V1/Pos/*` twins + `app/Http/Resources/Api/V1` resources; route groups with
  `feature:pos` + `can:` at the route; the `AppMenuComposer` entries (A4b, per D-2 of
  Phase 18); Scramble annotations per `docs/base/api/api-architecture.md` § API Conventions.
- **Acceptance**: web/API capability diff clean by capability; no controller contains
  `DB::transaction` or inline `validate()`; `route:list` shows a gate on every write.
- **Tests**: `PosWebTest`, `PosApiTest`, `ApiDocumentationTest` as the drift gate.

#### P19-B6 — Voucher domain data & validation (moved from Phase 18 P18-B1/B2/B5)
- **Task**: `vouchers` + `voucher_usages` migrations per § 4; `App\Models\{Voucher,VoucherUsage}`
  with `Auditable` + `SoftDeletes` and factories; `app/Http/Requests/V1/Voucher/{StoreVoucherRequest,UpdateVoucherRequest,ClaimVoucherRequest}`
  (code normalised to uppercase and unique among non-deleted rows; quota ≥ 0 or null;
  `min_purchase` ≥ 0; for a capped percentage `max_discount` ≥ nothing — the cap is a
  ceiling, validated as ≥ 0); `app/Actions/V1/Voucher/ValidateVoucherAction` — answers
  *is this code valid for this member right now* (active, inside `starts_at`/`expires_at`,
  quota vs `claimed_count`, the member's usage count vs `per_member_limit`, cart subtotal
  ≥ `min_purchase`) and returns the discount **capped by `max_discount`**, or refuses with
  the error contract's code (`docs/base/api/error-contract.md`) — a `ValidationException`
  must not leak to the envelope; `ClaimVoucherAction` — inside `DB::transaction`:
  `Voucher::where('code',…)->lockForUpdate()->firstOrFail()` → re-run the validity checks
  → conditional increment of `claimed_count` (`WHERE claimed_count < quota OR quota IS
  NULL`) → insert the usage row → audit `voucher.claimed`. The unique
  `(voucher_id, member_id)` row is the idempotency backstop the lock cannot be alone
  against (D-5). Add `Voucher` to the `naming-conventions.md` domains table (the P18 D-7
  rule, now for this domain).
- **Acceptance**: a double-submit cannot claim twice even with the lock inert (SQLite —
  D-5, and the docblock says so); an exhausted quota is refused; a failed claim rolls back
  counter *and* usage row together with its audit; `ValidateVoucherAction` is a read — it
  writes nothing and can be called by the cart preview without side effects.
- **Tests**: `VoucherRaceConditionTest` (E1), `VoucherValidationTest`, `VoucherRequestTest`.

#### P19-B7 — Voucher controllers, routes & its own menu (moved from Phase 18 P18-B6)
- **Task**: thin `app/Http/Controllers/Web/V1/VoucherController` (the A6 view finally gets
  its route) + `app/Http/Controllers/Api/V1/Voucher/*` twins + `app/Http/Resources/Api/V1`
  resources; route groups carrying `feature:vouchers` + `can:` at the route; **one
  `AppMenuComposer` entry of its own** — `'permission' => 'vouchers.view'`,
  `'feature' => 'vouchers'` — not a child of Members (spec 11); Scramble annotations per
  `docs/base/api/api-architecture.md` § API Conventions.
- **Acceptance**: web/API capability table diffed clean by capability; the HTTP round trip
  of the A6 fixtures finally runs (the D-4 debt is paid here); `route:list` shows the gate
  on every write; killing `vouchers` drops the menu entry for a superadmin too.
- **Tests**: `VoucherWebTest`, `VoucherApiTest`; `ApiDocumentationTest` as the drift gate.
- **Docs**: new `docs/base/features/vouchers.md` (what the domain is, what validates it at
  payment time, and that it belongs to neither membership nor the gateway).

### Group C — Gateway integration, webhook shield, credentials

Exit gate: every gateway call is fake-able (`Http::fake`) and the webhook is provably
single-settle.

#### P19-C1 — `CreatePaymentOrderAction`
- **Task**: `app/Actions/V1/Payment/CreatePaymentOrderAction` — generate the deterministic
  `reference` **before** the call (it is the idempotency key, D-3), compute PPN/PPH from
  `SystemSetting` rates, apply the voucher discount through `ValidateVoucherAction` (B6), persist the transaction as `pending`,
  then call the gateway through a driver with `timeout(5)` and
  `retry(3, …, $when: connection-or-timeout only)`, wrapped by
  `GatewayCircuitBreaker` (D-3).
- **Acceptance**: an open breaker refuses before any socket is opened and answers a
  distinct error code (`docs/base/api/error-contract.md`) so the cashier falls back to
  cash; a retried call carries the same `reference`; a gateway 4xx does **not** retry; the
  pending row survives a failed call and is auditable.
- **Tests**: `PaymentOrderTest` (with `Http::fake`, asserting attempt counts).

#### P19-C2 — `ProcessPaymentWebhookAction`
- **Task**: verify the HMAC over the raw body with `hash_equals()` and the stored
  `payment_webhook_secret` **before** parsing anything → resolve by `reference` →
  `DB::transaction` with `lockForUpdate()` **and** the atomic
  `WHERE status = 'pending'` update → insert the `payment_logs` row keyed on
  `(gateway, event_id)` → on the first settle only: mark `paid`, complete the POS side
  (stock, points, shift cash) or activate the membership subscription through
  Phase 18's Action → audit `payment.webhook_settled` / `payment.failed`.
- **Acceptance**: a duplicate delivery settles exactly once (second delivery is a 2xx
  no-op, not a 500); an out-of-order `failed` after `paid` does not un-settle; a bad
  signature is refused before any query runs and is logged without the secret; an unknown
  `reference` answers the same shape as a bad signature (no enumeration oracle); the
  handler returns fast and any heavy follow-up is dispatched after commit.
- **Tests**: `PaymentWebhookTest`, `PaymentWebhookRaceConditionTest` (E1).
- **Docs**: `docs/base/security/rate-limiting.md` gains the new limiter row.

#### P19-C3 — `UpdatePaymentSettingsAction`
- **Task**: the **only** writer of the three credential keys (D-6): validate, compare the
  submitted plaintext against `AppServiceProvider::storedSecret()` and re-`encrypt()` only
  on a real change, strip the secrets from the audit payload, then rebind
  `config('payment.*')` **after commit** through `AppServiceProvider::bindPaymentConfig()`
  — the `bindMailConfig()` shape, never at boot.
- **Acceptance**: a blank submission keeps the stored ciphertext; `GET /api/v1/settings`
  and `SystemSetting::getAll()` cannot see these keys; the audit row records *that* a key
  changed, never *what*; a driver switch takes effect at runtime without a redeploy
  (proven under a cached config, the P17-C2 method).
- **Tests**: `PaymentSettingsPersistenceTest` + the standing `SettingsPentestTest` shape
  (the credential-disclosure probe is the one that found Phase 9's finding #1).

#### P19-C4 — Payment controllers and the webhook route
- **Task**: `app/Http/Controllers/Web/V1/{PaymentGatewayController}` + `Api/V1/Payment/*`,
  the sidebar entries (A4b), and the public route `POST /api/v1/payment/webhook/{driver}`:
  **no** `auth:sanctum`, no `web` group (D-12 — so no CSRF `except` entry), its own named
  `RateLimiter::for('payment-webhook')` bucket, `feature:payment_gateway`, a bounded
  request size, and a `200`/`4xx` answer that never echoes the payload.
- **Acceptance**: the route is outside the `web` middleware stack (asserted, not assumed);
  the limiter returns the contract's 429 envelope; a `GET` to it is 405; the generated
  OpenAPI document carries it.
- **Tests**: `PaymentWebhookRouteTest`; `ApiDocumentationTest`; `CsrfProtectionTest`
  re-run unchanged (D-12's "do not fix the empty except list" is checked by it staying green).

### Group D — Enforcement

#### P19-D1 — RBAC
- **Task**: `POS`/`PAYMENTS`/`VOUCHERS` in `app/Support/PermissionCatalog.php` — `pos.access`,
  `pos.manage`, `pos.void_transaction`, `payments.view`, `payments.manage`, plus the
  voucher domain's own pair moved from Phase 18: `vouchers.view`, `vouchers.manage` —
  seeder
  prune/create/assign, role matrix, every write route carrying `can:`, FormRequest
  `authorize()` naming the same permission.
- **Note the shape**: `pos.access` is deliberately coarser than `pos.manage` because a
  cashier is not a manager, and this is the repo's first **non-admin** permission on a
  money write path — the role matrix has to say which role holds it (recorded as O-3).
- **Acceptance**: a `pos.access`-only user 403s every void, settings and dashboard route;
  a zero-permission user 403s the cashier page itself and every voucher screen on both
  channels; `payments.manage` alone cannot ring a sale.
- **Tests**: `PosAuthorizationTest`, `PaymentAuthorizationTest`; catalogue-drift guards updated.

#### P19-D2 — Feature-flag enforcement
- **Acceptance**: `pos` off → **403** (not 404, D-1) on both channels + the sidebar entry
  drops for superadmin too; `payment_gateway` off → the QRIS/gateway path 403s while the
  **cash** path still completes (this is the assertion that proves the two-flag decision in
  A4a); `vouchers` off → 403 on every voucher screen on both channels and its own menu
  entry drops — killing vouchers must NOT take the POS or membership down with it (the
  three-flag independence, spec 11); an undeclared slug fails closed.
- **Tests**: the per-slug flag middleware shape, plus the cash-survives-gateway-kill case.

#### P19-D3 — Audit events
- **Task**: `pos_shift.opened`, `pos_shift.closed`, `pos_transaction.created`,
  `pos_transaction.voided`, `payment.order_created`, `payment.webhook_settled`,
  `payment.failed`, `payment.settings_updated`, `voucher.claimed` (travelling with the
  voucher domain from P18-D3) — each written inside its Action's
  transaction, each with `causer = 'SYSTEM'` where the actor is the webhook/scheduler.
- **Note**: the brief's four events miss shift-close, void and settings-change; a money
  module whose void and float-variance writes have no trail is the thing an owner asks
  about at 2 a.m. `payment.settings_updated` must carry **no** credential value (C3).
- **Acceptance**: events enumerated from `->audit()` call sites, added to the Event Map
  (Phase 16's 48 → P18's 52 → 61 — the voucher move shifted `voucher.claimed` from P18's
  four to this phase's nine), and each row commits or rolls back with its mutation.
- **Docs**: `docs/base/features/audit-trail.md` Event Map; `docs/base/security/data-protection.md`
  for the `payment_logs.payload` redaction rule.

### Group E — Verification and closure

#### P19-E1 — Feature test suite
- **Task**: `tests/Feature/POS/{PosTransactionTest,PosShiftTest,ReceiptPrintTest,PaymentOrderTest,PaymentWebhookTest,PaymentWebhookRaceConditionTest,FinancialDashboardTest}.php`
  + `tests/Feature/Voucher/VoucherRaceConditionTest.php` (moved from P18-E1: quota
  arithmetic, the unique usage-row backstop, a double claim refused)
  plus the per-task files. Race test: two concurrent deliveries of the same event id → one
  settle, one no-op, proven by the conditional update + the unique index (D-5). Financial:
  tax and change arithmetic on exact `decimal` comparisons, including the rounding edge a
  50 %-off + 11 % PPN cart produces.
- **Acceptance**: every gate sabotage-verified (remove the unique index → red; remove the
  status guard → red); bars unmoved — global ≥ 85 %, Actions ≥ 95 %, Requests ≥ 95 %,
  Policies 100 %; wall clock stays recorded, not asserted (D-8 of Phase 15).
- **Tests**: are the tests.

#### P19-E2 — Full-suite run and tracker reconciliation
- **Task**: `vendor/bin/pint --test`, `vendor/bin/phpstan analyse --memory-limit=1G`,
  `php artisan test`, `route:list`, `schedule:list`, `composer audit --locked`,
  `npm ci && npm run build`; flip rows on evidence; update `progress.md`,
  `implementation-roadmap.md`, `qa-tracker.md`, `docs/base/`.
- **Acceptance**: counts quoted from the run; PHPStan ledger stays at 310 entries or its
  growth is named; `composer audit` includes the new escpos dependency; nothing is DONE
  without an assertion someone opened and read; the deferred-evidence list (§ 7) is carried
  into `progress.md` § *Known Issues*, not dropped at closure.

---

## 6. Execution order and per-group gates

| Gate | Must be true before the next group opens |
|---|---|
| A | fixture render green, no DB and **no secret** in any view, all three flags declared + seeded, Pint clean |
| B | migrate/rollback exit 0, Actions ≥ 95 %, oversell + shift reconciliation proven, `composer require` blocked until ADR-031 exists |
| C | every gateway call faked with asserted attempt counts, duplicate webhook settles once, credentials invisible to `getAll()` and the API |
| D | every refusal measured at its status code (403 permission, 403 flag, 405 wrong verb), cash path proven alive with `payment_gateway` off, Event Map updated |
| E | four gates exit 0, trackers reconciled, deferred evidence recorded |

## 7. What stays open after this phase (carried into `progress.md` § Known Issues)

- **Real hardware**: a 58/80 mm printer's actual output (D-9) — the byte stream is proven,
  the paper is not.
- **A live gateway**: sandbox order → webhook → settle against a real vendor account.
  `Http::fake` proves our shape, not theirs (their signature scheme, clock skew, and
  webhook retries are discovered here or not at all).
- **The MySQL two-session probe** for `lockForUpdate()` (D-5) — inert on the test
  connection, and it now covers the voucher claim and the points ledger too, both locks
  travelling with their rows.
- Production credential rotation, and the PCI question nobody has answered: what this
  store is allowed to keep in `payment_logs.payload`.

## 8. Open owner decisions

| # | Question | Options | Blocking? |
|---|---|---|---|
| O-1 | **D-4** — how does a product exist? | thin admin CRUD inside this phase / a separate master-data phase / seeder-only for the pilot | Blocks B1's commit. The table is forced either way; the *screen* is not |
| O-2 | **D-11** — cost price | add + maintain `products.cost` and ship the Profit card / ship revenue + fees + tax now, Profit later | Not blocking; decides whether A3's fourth card is real or a zero |
| O-3 | Which role may ring a sale | a new `cashier` role holding `pos.access` / `user` role extended / per-outlet role later | Blocks D1's seed matrix. A base project that invents a role is making a product decision |
| O-4 | Gateway vendor | Midtrans / Xendit / a driver interface with one implementation | Not blocking A/B; decides C1's driver shape. **Recommendation: write no second driver** — one vendor behind the interface until a second is actually asked for |
| O-5 | `payment_logs` retention | reuse the daily `sweeps` pattern with `payment_log_retention_days` / keep forever | Not blocking; money ledgers usually keep, logs do not |
