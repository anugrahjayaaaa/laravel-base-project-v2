# Phase 19 — Basic POS Engine & Omnichannel Payment Gateway

> Date: 2026-11-05 | Status: **PLANNED** (breakdown written 2026-11-05; Midtrans research
> folded in 2026-11-08 — D-14..D-23, one new task C5; receipt-hardware / PPN / financial-report
> research the same day — D-24..D-31 and four new tasks A7, A8, B8, B9) except the
> voucher Group A slice, which shipped 2026-11-05 and sits in REVIEW (P19-A6) |
> Branch when scheduled: `feature/phase-19-pos-and-payment`
> Gateway: **Midtrans, Core API v2 (VT-Direct)** — owner decision 2026-11-08, closes O-4,
> recorded as ADR-036. **No gateway Composer package** (D-15): the six endpoints go through
> `Illuminate\Support\Http`.
> Printer: **Eppos EP58M / RPP02 — a 58 mm ESC/POS thermal unit on USB** (owner decision
> 2026-11-08), the only hardware this phase calibrates for (D-28); `mike42/escpos-php` **v5.0**
> is the version ADR-037 writes down, because v5 changed the PHP floor, the image dependency
> and the test connector (D-27).
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
> own** (B6) since the voucher move. Phase 18 in REVIEW does not block starting this phase's
> Group A; it blocks B2.
> Renumbering: this was Phase 18's sibling "Phase 19" until 2026-11-05, then **Phase 20**
> (ADR-033), and is **Phase 19** again since 2026-11-08 (ADR-035) — every `P20-*` ID in the
> planning tree became `P19-*`.
> Detail authority: this document. `task-tracker.md` holds the summary rows only.
> Design authority: `docs/base/`. A change to a design decision goes through an ADR in
> `docs/planning/decisions.md` — and this phase needs one before `composer require` (D-9).
> Written already: **ADR-036** (Midtrans, no gateway package — adds no dependency),
> **ADR-037** (`mike42/escpos-php` v5 + the EP58M/RPP02 profile), **ADR-038** (the PPN mode,
> the per-transaction tax snapshot, and what PPh is allowed to mean here).

---

## 1. Scope

Twenty-seven tasks in five groups (the brief's nineteen, plus the three voucher rows —
A6/B6/B7 — moved in from Phase 18 on 2026-11-05, plus **C5**, the stale-payment reconciliation
sweep the Midtrans research made unavoidable on 2026-11-08, plus the four rows the printer /
PPN / financial-report pass of the same day added: **A7** the receipt-and-tax settings card,
**A8** the monthly/annual report screen with its export button, **B8** the tax engine
(include-vs-exclude, `pembulatan`, service charge) and **B9** the period aggregates + the
report export).

| Group | Subject | Nature |
|---|---|---|
| A | Cashier POS UI, gateway settings card, receipt + tax settings card, financial dashboard, monthly/annual report screen, voucher admin view, flag declaration, render gates | **Build** (UI only) |
| B | Migrations, models, shift + transaction Actions, tax engine, ESC/POS print for the EP58M, voucher domain, period aggregates + report export, controllers | **Build** |
| C | Gateway order creation, atomic webhook, encrypted credential writer, stale-payment reconciliation, controllers | **Build** |
| D | Route authorization, flag enforcement, audit events | **Enforce + verify** |
| E | Transaction/webhook/financial tests, four gates, tracker reconciliation | **Verify + close** |

**Not in scope:**

- **A product master-data module.** See **D-4** — the schema has no products, no stock,
  no cost. This phase gets the smallest `products` table that lets a sale be rung up, not
  a purchasing/inventory system (no supplier, no purchase order, no stock take, no
  multi-warehouse).
- Refunds **initiated** through the gateway. A void is a reversal row in our ledger (D-8);
  Midtrans' refund API is allowed only from `settlement` and is **disabled by default on POS
  accounts** until the vendor activates it commercially (D-23). Refund and chargeback
  *notifications* are in scope — they arrive against a settled row whether we call them or not.
- Online checkout, invoicing, a customer-facing payment page, subscription auto-charge
  (a card-on-file vault), or anything storing a PAN.
- **An expense / petty-cash ledger.** "Uang keluar" in the owner's dashboard picture is real
  (the drawer does lose money), but the only money-out rows this schema has are voids,
  refunds/chargebacks and the shift float (D-29). Supplier bills, owner drawings and shop
  expenses are a purchase/expenses module — its own phase, and the one that would make a
  Profit card honest (O-2, O-10).
- **Filing the tax.** B8/A8 compute and export a **rekap PPN keluaran** for the accountant;
  issuing the Faktur Pajak, e-Faktur/Coretax upload and the masa-PPN SPT are the PKP's own
  act in DJP's systems (D-24). This phase never claims to file anything.
- **XLSX or PDF report formats** — the export is CSV through the existing house pattern
  (D-30); a spreadsheet or PDF library is a new Composer package and its own ADR.
- **Snap (the browser popup) and BI-SNAP / Snap-BI** (the SDK's v2.6 national-standard API,
  its own client-id + RSA-key auth and a different notification signature). Neither is a
  counter flow here (D-14, D-16). Card-present is out too: Midtrans' Offline EDC terminal
  has no `/v2/charge` path (D-14).
- Multi-Outlet / per-register device identity, and offline cashier mode (a queue that
  replays when the line drops). Each is its own phase.
- i18n (`AGENTS.md`: no `lang/` source exists — every string here is literal English).
- Real-hardware printer calibration and a live gateway sandbox round trip — recorded as
  deferred runtime evidence (§ 7), the Phase 14 precedent. The sandbox **does** deliver real
  notifications, but only to a publicly reachable URL (§ 7).

### Why this phase reuses almost everything it needs

| Need | Existing owner | What Phase 19 adds |
|---|---|---|
| Encrypted credentials in `SystemSetting` | `encrypt()` on write, `AppServiceProvider::storedSecret()` compare, `SystemSetting::SECRET_KEYS` redaction, blank-means-unchanged | **one** gateway key (`payment_server_key`) following the `mail_password`/`storage_s3_secret` shape exactly — the webhook secret this breakdown planned does not exist (D-16) |
| Runtime rebinding of config after commit | `AppServiceProvider::bindMailConfig()`, `bindStorageConfig()` | one `bindPaymentConfig()` — and the reason the vendor SDK is refused (D-15): its credentials are process-global statics |
| Outbound HTTP with timeout + retry | `Illuminate\Http\Client` — `timeout()` (`PendingRequest.php:644`), `retry($times, $sleep, $when)` (`:671`) | nothing; both are native, and neither exists in the vendor SDK (D-15) |
| Circuit breaker | **not in the framework on this install** (D-3) | one small cache-backed class |
| Ungated public API route | `GET /api/v1/health` with `throttle:health` | the webhook, same shape |
| Atomic conditional state transition | the `unlock`/last-superadmin refusals, `JobConventionTest`-adjacent patterns | `UPDATE … WHERE status = 'pending'` + affected-rows check |
| Money-in / money-out audit | `Auditable::audit()` inside the transaction, `causer = 'SYSTEM'` for system rows | thirteen event names (D3) |
| Queued work with a named queue | `App\Support\QueueName`, `bin/run-workers.sh` | gateway calls on `exports`, sweeps on `sweeps` |
| "Generate a report file, tell me when it is ready, let me download it" | **the whole thing already exists**: `AuditExport` + `AuditExportStatus` + `AuditExportRequestAction` (row before the job, `->afterCommit()`) + `GenerateAuditExport` (`chunkById`, a `php://temp` stream assembled before anything touches disk, private disk, `failed()` marks the row *and* unlinks the partial file) + `AuditExportDownloadAction` (ownership check) + `AuditExportReadyNotification` + `StorageCapacityGuard` | the same shape with its own table and its own permission — **not** a second export framework and **not** a re-use of the audit row (D-30) |
| Printing an image (the receipt logo) | `mike42/escpos-php` v5's `mike42/gfx-php` (pure PHP, no GD/Imagick requirement) — and `ext-gd`, `ext-intl`, `ext-zlib` are all present on this box (`php -m`) | the printer profile and the 384-dot width cap, which is a validation rule, not a dependency (D-27) |
| A rate that is policy, not code | `SystemSetting` + the `tax_*` keys the brief already named; `AppServiceProvider::bind*` for the read-after-write | the PPN mode (`include`/`exclude`) and the per-transaction snapshot of rate + mode + DPP, so a rate change never rewrites a printed receipt (D-24, ADR-038) |

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
   conditional update plus a unique **notification hash** (`(gateway, order_id, status,
   status_code, gross_amount)` — Midtrans sends no event id, D-17); `lockForUpdate()` is
   defence in depth on MySQL only (D-5).
5. **Our `reference` is the idempotency key the gateway honours** — through `order_id`
   uniqueness (`status_code 406` on a duplicate), not through the `Idempotency-Key` header,
   which Midtrans remembers for only five minutes (D-18). Retry a timed-out charge by
   **re-querying status first**, never by re-posting blind.
6. **Credentials never leave the secret path.** Not in a view value, not in
   `SystemSetting::getAll()`, not in the audit payload, not in the log line, not in a
   query string. `hash_equals()` for signature comparison — never `==`. The comparison value
   is a field concatenation hashed with the server key, not an HMAC over the raw body (D-16).
7. **Action-first, thin controllers, FormRequest validation, route-level `can:` +
   `feature:`** (`AGENTS.md`), one set of Actions behind both channels, and the web/API
   capability table diffed **by capability** before the phase closes (the Phase 12 method).
8. **Money is `decimal`, never `float`, never a PHP arithmetic accident.** Sums happen in
   SQL or in `bcmath`-free integer minor units; the schema stores `decimal(12,2)` and the
   tests use exact comparisons. At the gateway boundary money is **whole rupiah** — the charge
   payload sends `(int) round($total)` and the rounding happens **once, at the document**
   (B8/D-25), not per line, so the receipt, the ledger and Midtrans carry the same integer.
9. **No inline FQCN** (`NoInlineFqnTest`); **cache primitives only** (a Collection through
   the Redis store returns `__PHP_Incomplete_Class` on a hit — `AGENTS.md`); new code does
   not grow the 310-entry PHPStan ledger.
10. **A new Composer package stops the phase** until `docs/planning/changelog.md` + an ADR
    exist (`AGENTS.md`). This phase has exactly one candidate (D-9, escpos): the gateway adds
    none, because the vendor SDK cannot serve the gates this phase is built on (D-15, ADR-036).
11. **Voucher is a domain, not a feature of its neighbours.** Since the owner move of
    2026-11-05 the voucher has its own flag (`vouchers`), its own menu entry, its own
    permissions (`vouchers.view`/`vouchers.manage`) and its own tables. POS and the
    gateway **consume** it only: a sale presents a code, `ValidateVoucherAction` answers
    valid-or-not plus the capped discount, `ClaimVoucherAction` records the usage — no
    screen outside `pages/vouchers/` edits voucher data.
12. **The gateway is Midtrans, and the product is Core API v2 (`POST /v2/charge`).** The
    cashier rails are **QRIS + one VA bank + cash** (EDC recorded out-of-band); one Midtrans
    transaction per non-cash tender line, split tender is two ledger lines against one sale,
    never one call for a partial amount (D-14). Goods leave the counter only on
    `transaction_status = settlement` — the customer's app screen is not evidence (D-22).
13. **Tax is data, never a constant, and the mode is fixed per sale.** `tax_ppn_applied`
    (is this merchant charging PPN at all — a PKP question), `tax_ppn_rate`, `tax_mode`
    (`include` = the shelf price already contains the tax / `exclude` = it is added on) and
    `tax_rounding` live in `SystemSetting`; the transaction row stores the **rate, mode and
    DPP it was computed with**, and both the receipt and the tax report read the stored row,
    never today's setting (D-24, ADR-038). A rate that has moved 10 → 11 → 12-with-DPP-nilai-lain
    in four years makes this the difference between a report and an accident.
14. **The receipt is a template, and its canvas is 32 columns.** Logo (≤ 384 dots wide),
    store name, address, phone, NPWP/PKP line, a configurable footer and the PPN line are
    operator data edited in A7, not strings in a Blade file; the body is lines + totals in the
    order the owner named (item, qty × price, per-line discount, subtotal, service charge,
    total discount, PPN or its inclusion note, rounding, grand total). A line that does not
    fit 32 columns has a stated wrap/truncate rule and a test that proves it (D-28).
15. **One export shape in this application.** A report file is produced the way an audit
    export already is: a row written before the job, a queued job that chunks and streams to
    a finished CSV on private disk, an ownership-checked download, a ready notification, and a
    `failed()` that marks the row and unlinks the partial file (D-30). A browser-side "print
    to Excel" trick is not an export and is not used.
16. **Money the merchant keeps and money the merchant owes are different columns.** PPN
    collected is a **liability** (it belongs to DJP the moment it is charged, which is why the
    dashboard's "total PPN yang harus dibayar" is a sum over `tax_total` of settled sales in
    the period, not a slice of revenue), and PPh is the merchant's own tax — never a customer
    line, never printed (D-31).

---

## 3. Deviations from the brief (measured against the shipped code)

| # | The brief said | The repository does | Consequence |
|---|---|---|---|
| **D-1** | P19-D2: flag disabled → **HTTP 404** | **403** — settled in `EnsureFeatureIsEnabled` ("Why 403, not 404", 2026-10-01), recorded by Phase 12 as "not to be *fixed* into a bug", pinned by 62 tests, and re-asserted as P18 D-1 | D2 asserts 403 on both channels |
| **D-2** | P19-A1: "Fast-responsive screen (**Livewire/Alpine**)" | Neither is installed: `package.json` devDependencies are vite, laravel-vite-plugin, bootstrap, tailwindcss (D-8 of Phase 17), concurrently; `AGENTS.md` says no Vue/React; house JS is vanilla modules under `resources/js/` (`bulk-actions.js`, `password-strength.js`) rendered through `@vite` and `@nonce`d because CSP omits `'unsafe-inline'` (`ContentSecurityPolicyTest` pins it). Livewire's inline `wire:` behaviour and its payload round trips both fight that gate | The cashier screen is **one Blade page + a vanilla JS cart** posting to the JSON endpoints B5/C4 ship. No new frontend dependency, no CSP exception. Cart state lives in the browser; **the server recomputes every total** (D-7) |
| **D-3** | P19: "HTTP Client retry (3 retries, timeout 5s) **and circuit breaker pattern**" | `Http::timeout()` and `Http::retry()` are native (`PendingRequest.php:644`, `:671`) — zero code needed. **`Illuminate\Support\CircuitBreaker` does not exist in this install** (verified: no class and no matching file under `vendor/laravel/framework`) | Retry/timeout: use the framework. Breaker: one small cache-backed class (`App\Support\GatewayCircuitBreaker`, ~40 lines, open after N consecutive failures, half-open probe after a backoff, state in `Cache`) — the only genuinely new infrastructure this phase gets. **And retry is unsafe on a non-idempotent call**: retried only on connection/timeout errors, always with our deterministic `order_id`, and an open breaker must answer a distinct error code so the cashier falls back to cash rather than hanging |
| **D-4** | P19-B1: `stock_movements`, `transaction_items` … | **There is no product table.** `database/migrations` contains no product/inventory/stock/payment/transaction migration; `app/Models` has none. `transaction_items` has nothing to reference and stock has nothing to deduct **from** | B1 must add `products` (`sku` unique, `name`, `price decimal`, `cost decimal` nullable, `stock_qty`, `is_active`). **No product CRUD is implied** — see O-1: the owner decides how a product gets into the table (thin admin screen vs seeder vs import) before B1 is committed |
| **D-5** | P19-C2: `lockForUpdate()` is the race shield | `phpunit.xml` runs `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:` — `FOR UPDATE` parses and does nothing, so a test that relies on it is a false green (the same trap Phase 18 recorded, and the voucher race test now travels here with the domain) | The real shield is portable and is what E1 proves: an **atomic conditional update** (`WHERE id = ? AND status = 'pending'`, then assert affected rows = 1) **plus a unique index on the gateway event id**. `lockForUpdate()` stays in the Action as the MySQL half; the MySQL two-session probe is deferred runtime evidence. **What does not survive the vendor choice: the "unique index on the gateway event id" — Midtrans sends no event id (D-17)** |
| **D-6** | P19-A2 + C3: gateway keys in `SystemSetting` | `SystemSettingsUpdateAction` holds **one whitelist** of acceptable keys and `GET /api/v1/settings` answers from `SystemSetting::getAll()` minus `SECRET_KEYS`. Phase 9's first finding was that a credential reachable through the settings module (`settings.view`) is read by a wider audience than the one it was built for | The three keys are added to `SystemSetting::SECRET_KEYS` and **deliberately NOT** to the settings whitelist — so `PUT /settings` and `GET /api/v1/settings` can neither write nor read them. `UpdatePaymentSettingsAction` (C3) is the **only** writer, behind `payments.manage`. A `settings.manage` holder must not be able to repoint production money. Blank-means-unchanged and the plaintext compare come from `SystemSettingsUpdateAction:213-234` as shipped. **The count changes: Midtrans needs one credential, not three (D-16)** |
| **D-7** | P19-A3: "Include/Exclude Tax filter toggles" (UI-side) and A1's cart | Nothing in this codebase trusts a client-side number, and it must not start here: a tampered cart total is a revenue leak, not a UI bug | Prices, tax, discount and change are computed **server-side from `products` and `SystemSetting` rates**; the browser's figure is a preview only. `ProcessPosTransactionAction` re-derives every line and refuses a mismatch |
| **D-8** | P19-D1: `pos.void_transaction` | "Void" of a settled payment is a **money event**. The house deletion policy (`docs/base/data/soft-delete.md`) and the ledger convention Phase 18 used (`member_point_histories`) both say: never edit or delete the row that happened | A void inserts a reversing `pos_transactions` row (`kind = 'void'`, negative amounts, `void_of_id`), audited, and re-stock through `stock_movements`. Nothing is deleted, no status field flips a paid row to unpaid |
| **D-9** | P19-B4: `mike42/escpos-php` | `AGENTS.md`: a new Composer package needs `docs/planning/changelog.md` + an ADR **first**. The package needs `ext-mbstring`/`iconv`; using it for *images* pulls GD/ImageMagick, and no thermal printer exists in CI or in the test suite | **ADR-037** before any `composer require`. Receipts use the printer's **native** QR/barcode commands (no GD). The print path is verified by asserting the **byte stream** (escpos renders to a buffer — that is testable); "the paper came out right on a 58 mm head" is deferred runtime evidence. Cash-drawer kick is one `Escpos` call, never a retry loop |
| **D-10** | P19-E: "**100 % test coverage**" | The enforced bars are global ≥ 85 %, Actions ≥ 95 %, Policies 100 %, Requests ≥ 95 % (`bin/coverage_gate.php`, bars assigned as P15 D-13). No 100 % bar exists and the phase must not lower or fake one | E1 states the bars and reports what the run measured. Coverage gaps are a named list, not an averaged claim |
| **D-11** | P19-A3: "**Net Profit**" card | Profit needs a cost, and nothing in the schema has one (D-4 — a product has a price). Gross revenue and tax are computable from `pos_transactions` + `payment_logs`; profit is not, and **neither are the gateway fees** (D-20) | Either `products.cost` is filled and maintained (a decision with a data-entry consequence nobody named), or the Profit card ships in a later phase. Recorded as O-2; A3 is written to render it only when cost data exists |
| **D-12** | (unstated) webhook = a public POST | CSRF runs on the `web` group only (`ValidateCsrfToken` replaced `PreventRequestForgery` **in that group**, P13-B), and its `$except` list is empty **and pinned** by `CsrfProtectionTest`. The gateway never sends a session cookie | **No `except` entry is needed** — an `api/v1` route is not in the `web` group. Do not "fix" the empty except list. The webhook does need: no `auth:sanctum`, its own named `RateLimiter::for()` bucket (15 exist today; `rate-limiting.md` owns the table), signature verification before any DB read (a field-concatenation hash, not an HMAC — D-16), and a bounded payload |
| **D-13** | P19-B1: `transactions`, `transaction_items` | A table literally named `transactions` next to `payment_logs`, `member_subscriptions` and a future accounting import is the kind of generic name `naming-conventions.md` exists to prevent; the domain folder here is `Pos` | Tables are `pos_transactions` / `pos_transaction_items`; `stock_movements`, `pos_shifts`, `payment_logs` keep the brief's names. One `use` per domain in the `naming-conventions.md` domains table (the P18 D-7 rule) |
| **D-14** | O-4: "Midtrans / Xendit / a driver interface with one implementation" | **Owner picked Midtrans 2026-11-08.** The product that fits a counter is **Core API v2 (VT-Direct)**: one `POST /v2/charge` with `payment_type` `qris` or `bank_transfer` returns `qr_string` (the EMVCo payload) or `va_numbers[].va_number`, and the money is confirmed later by server notification. **Snap** is a browser popup for online checkout; **Snap-BI / BI-SNAP** (SDK ≥ 2.6) is a different product with client-id + RSA-key auth, an access token per call and its own signature scheme. Card-present is **not in Core API at all** — Midtrans' Offline EDC terminal never appears in `/v2/charge`, and an online card charge needs a 3DS `redirect_url` (10-minute life) that a cashier does not have | This phase's rails: **QRIS + one VA bank + cash + a recorded EDC line**. `payment_method` enum becomes `cash|qris|va|edc` (`gateway` was never a method) and `edc` calls nothing — the cashier keys the terminal's imprint and that line is only as good as the acquirer's payout (§ 7). Split tender (part cash, part QRIS on one Midtrans transaction) is not a documented capability, so it is two ledger lines against one sale. O-4 closes; the driver interface is **not** built (one vendor, one implementation — the global rule against an interface with no second caller), it is an `App\Support\Gateway\` class the Actions call. Sources: `docs.midtrans.com/reference/{charge-transactions-1,qris,virtual-account-api-bank-transfer}`, `Midtrans/Config.php` URLs |
| **D-15** | "Use the official `midtrans/midtrans-php` SDK" | The package is real and official (2.6.2, MIT, released 2025-03-18, `php >=5.4` + ext-curl/json/openssl) and it is a **static-property wrapper around raw cURL** — and every gate this phase was written against sits on what it does not do: `ApiRequestor::remoteCall` sets **no `CURLOPT_TIMEOUT` / `CURLOPT_CONNECTTIMEOUT`** (0 = block forever: a hung gateway socket holds a cashier's request open, the same failure D-9 refuses for a dead printer); it bypasses Guzzle, so **`Http::fake()` cannot intercept it** and C's exit gate ("every gateway call is fake-able") is unreachable; the SDK's own stub `Midtrans\MT_Tests` lives under `tests/` = `autoload-dev`, **never installed** in a real app; `Midtrans\Notification::__construct` reads `php://input` **and fires an outbound `Transaction::status($transaction_id)` call** per webhook — trusting an attacker-supplied id, verifying no signature, with no timeout; and `Config::$serverKey` / `$isProduction` are **process-global statics**, so a long-lived worker or a cached config can charge against the wrong environment (the `AGENTS.md` boot-order trap, in the one place a mistake moves money) | **No gateway package** (ADR-036). Six endpoints through `Illuminate\Http\Client`: `Http::asJson()->withHeaders(['Authorization' => 'Basic '.base64_encode($serverKey.':'])])`, `timeout(5)`, `connect_timeout(2)`, `retry(3, ms, $when: connection/timeout/5xx)`, plus the breaker (D-3) and `Http::fake()` fixtures. The SDK's request/response shapes and `docs.midtrans.com/reference/*` are the spec. **If the owner overrules**: set `Config::$curlOptions[CURLOPT_CONNECTTIMEOUT]` + `[CURLOPT_TIMEOUT]` before the first call, leave `Config::$isSanitized` at its source default `false` (the README claims otherwise; the sanitizer only trims `item_details`/`customer_details`, never `order_id` or amounts), never use `Midtrans\Notification`, and accept that E1's gateway tests become live-sandbox network tests CI cannot run |
| **D-16** | P19-A2 + C3 + § 4: a `payment_webhook_secret` verified as an HMAC over the raw body | Midtrans Core API has **no webhook secret and sends no HMAC**. The notification carries `signature_key` = **SHA-512 over `order_id + status_code + gross_amount + ServerKey`**, concatenated in that order, with `gross_amount` taken **as the payload's own string** (`"145000.00"`, two decimals — the request sends the integer, the notification echoes the decimal string). Older Core API docs show MD5 over the same four fields, so the algorithm is a constant to prove against a real sandbox notification, not to assume. The payload leaks nothing (a hash is not a key), and Midtrans cannot attach an auth header to our endpoint | `payment_webhook_secret` **never exists**; `payment_client_key` is only for `/v2/token` card bootstrap, which is out of scope, so it is **not stored either** — a credential nothing reads is a credential that can only leak. The settings card and C3 drop from three credentials to **one** (`payment_server_key`). Verification becomes `hash_equals(hash($algo, $order_id.$status_code.$gross_amount.$serverKey), $notif['signature_key'])` **before any DB read** (spec 6 stands, its input changes); the algorithm is a named constant and a fixture test. BI-SNAP's `X-SIGNATURE` RSA + `X-Timestamp` verification is a second verifier for a second product, so enabling DANA/BI-SNAP is a phase, not a config flag. Sources: `docs.midtrans.com/docs/https-notification-webhooks`, `reference/handle-notifications`, `Midtrans/ApiRequestor.php` |
| **D-17** | P19-C2: dedupe on a "unique event key `(gateway, event_id)`" | **There is no event id.** One transaction emits **several** notifications (pending → settlement, then each refund/chargeback adds its own; a refund emits **two**, only the second carrying `bank_confirmed_at`), a non-2xx answer is retried on a schedule (**2/10/30/90/210 min, ≤5 tries**, response window ~15 s, notifier speaks TLS ≤1.2), and the docs state deliveries can arrive **out of order** ("settlement before pending"). `refund`/`partial_refund`/`chargeback` are legal transitions **from** `settlement`, so "already paid" is not a terminal state for the log, only for the settle path | The dedupe key is `notification_hash = sha1($gateway.'|'.$order_id.'|'.$transaction_status.'|'.$status_code.'|'.$gross_amount)` with a unique index, and `payment_logs` stores the vendor's raw `transaction_status`, `status_code`, `fraud_status`, `settlement_time`, `payment_amounts` alongside it. The settle transition stays the atomic `WHERE status = 'pending'` update (D-5 — now the load-bearing half). Delivery we already stored → **2xx no-op** (a 500 would buy five retries); bad signature → **401**, which stays visible in Midtrans' notification history instead of being swallowed. Out-of-order is refused by the state machine (`pending` after `settlement` does not un-settle), never by last-write-wins. Status vocabulary and which states are final: **D-23's** table |
| **D-18** | P19 spec 5: "the reference we generate is the idempotency key the gateway honours" | Honoured, but through **`order_id` uniqueness**, not the header: `order_id` is `String(50)`, symbols `- _ ~ .`, **no space**, and cannot be reused while the transaction is `pending` or `settlement` — a duplicate is refused with `status_code 406` ("Duplicate order ID"). `/expire` and `/cancel` free it. `Idempotency-Key` is real but remembered for **five minutes**, caches **successful** responses only (a concurrent in-flight retry answers 202), must be ≤46 chars, and is unsupported for Permata VA / CIMB Clicks / KlikBCA / Indomaret | `pos_transactions.reference` **is** the `order_id`: ≤50 chars (pinned in the Request and a test), one per **charge attempt**, never re-issued for a reprint (a reprint re-reads the stored row; a retried basket after a void gets a new reference, which is exactly why the reference cannot double as the basket id). The header still goes out carrying it — free — and nothing depends on it. What a response means: **201** created-pending, **200** created-terminal, **407** expired (a success body for `/expire`, not an error), **4xx on `/v2/charge`** = not created, **5xx = ambiguous** → `GET /v2/{order_id}/status` before anything is re-sent. That is C1's retry rule, and it is why the breaker's "distinct error code" (D-3) has a reconciliation path behind it. Sources: `reference/transaction-details-object`, `reference/api-headers`, `reference/status-code-4xx` |
| **D-19** | (unstated) how expiry and amounts are formatted | Expiry is a top-level **`custom_expiry {order_time?, expiry_duration: int, unit: second|minute|hour|day}`**. The `transaction_details.expiry{start_timestamp,…}` shape that circulates in old integrations is legacy Veritrans v1 — absent from the current reference **and** from the SDK. Defaults: QRIS/GoPay **15 min**, VA **24 h**, e-channel 2 h, 3DS card 15 min; the expiry **scheduler is a batch job**, so a window under ~15 min is documented as unreliable, and expiry *is* notified (SLA ≤90 s). `gross_amount` must be **integer IDR** ("do not add decimal"; item prices are validated as integers) and the channel floors/ceilings are real: **QRIS max Rp 10,000,000**, BCA VA min 10,000, BSI VA min 1,000, card min 10,000 | C1 sends `custom_expiry` (15 min for QRIS, never shorter) and stores the response's `expire_time` as `pos_transactions.gateway_expires_at` — the cashier countdown reads that column, not a client-side timer. A real-time void is `POST /v2/{id}/expire` dispatched **after commit** from the void Action, which also recycles the reference. Money crosses the boundary as `(int) round($total)` where `$total` is **the already-rounded payable figure B8 produces** (D-25), so the printed receipt, the ledger row and the gateway payload are the same integer; a basket over the QRIS ceiling or under a VA floor **refuses that tender** with a named reason before any call, never as a 400 at the counter. Sources: `reference/custom-expiry-object`, `reference/transaction-details-object`, `docs/is-there-a-minimum-and-maximum-transaction-value…` |
| **D-20** | P19-A3: "gateway fees" card | **No fee field exists in any notification and there is no fee API.** No `settlement_amount`, no `fee`: MDR (QRIS 0.7 % under the BI standard, card ≈2.9 %) is deducted from the **payout**, visible only in the Dashboard Payout/Daily Report (a 7-zip export) or the BI-SNAP Reporting APIs (`/v1.0/transaction-history-list`, service code 12) which need a separate credential set | A3 cannot compute fees from anything this phase stores. It ships settled gross, per-channel counts and tax; the fee and profit numbers wait for a report import (the D-11 pattern). **O-6** — inventing an MDR rate table would print a figure nobody can reconcile, on a dashboard whose whole purpose is being the money source of truth |
| **D-21** | P19-A1: the cashier screen shows the QR | The charge response gives `qr_string` **and** `actions[]` = `[{name: "generate-qr-code", method: "GET", url: "…/v2/qris/{transaction_id}/qr-code"}]`. That URL requires the server-key Basic header, so it cannot be an `<img src>` (and there is no `snapshot_image_url` field to point at). Nothing here renders a QR: `package.json` is bootstrap/tailwind/vite/concurrently only, no QR package in `composer.json`, and CSP omits `'unsafe-inline'`, so no inline generator script | **The printed receipt carries the QR** — `qr_string` through the printer's native QR command, which D-9's escpos dependency already provides (zero new dependency, and a thermal QR is what a counter customer actually scans). The screen shows the amount, the `gateway_expires_at` countdown and the raw string for a manual/paste path. An on-screen QR image needs a rendering package = its own ADR: **O-7** |
| **D-22** | P19-C2: the webhook is the source of truth for settlement | **It is not sufficient.** A payment that fails inside the customer's app **stays `pending` and is never notified** — the QR sits there, the cashier waits, nothing arrives — while expiry *is* notified. The docs say the opposite of the UI: *"verify the payment status from Midtrans … Do not deliver good/service if payment status on Midtrans is not settlement"*; a customer's success screen means nothing. A VA can also settle **partial**: `payment_amounts[].{paid_at, amount}` exists per bank and exact-amount enforcement is not promised | **New task C5** — `payment:reconcile-stale`, scheduled on the `sweeps` queue under the `AGENTS.md` job standard (named queue, `tries`/`timeout`/`maxExceptions`/`backoff()`, `failed()` logging the `request_id`): it takes `pos_transactions` still `pending` with `gateway_expires_at` in the past, calls `GET /v2/{reference}/status` (BI-SNAP-style channels must poll by `transaction_id`, which is why § 4 stores `gateway_transaction_id`), and settles or closes them through **the same Action the webhook uses** — one completion path, two triggers. A settled amount that does not equal the expected total is **recorded and refused completion**, held for a `payments.manage` review, never silently accepted (**O-8**). The customer-facing "paid!" state in the UI is driven by the store's `status`, never by a client-side poll. Sources: `docs/gopay-qris-pos-integration`, `reference/get-transaction-status` |
| **D-23** | § 1: refunds stay out of scope | Confirmed, with two consequences the brief did not have. Refund is allowed **only from `settlement`** (before it, cancel), `refund_key` is the merchant's own idempotency key (alphanumerics + `-`/`_`, same-key retry inside 7 days), repeated partials roll `partial_refund` → `refund`, and a refund emits **two** notifications — the second with `bank_confirmed_at`, which is the success signal. **For POS transactions the refund API is disabled by default until the Midtrans account is activated commercially.** Chargeback is card-only, issued by the cardholder's bank without asking us, and arrives as `chargeback`/`partial_chargeback` with a `refund_chargeback_id` | Refund *initiation* stays out of scope and its activation becomes an operational prerequisite (§ 7), not a task. Refund and chargeback **notifications** come in scope, because they land on an already-settled row and would otherwise read as revenue: C2 records them as reversing `pos_transactions` rows (`kind` gains `refund` and `chargeback`; D-8's never-edit-the-row rule unchanged; `causer = 'SYSTEM'`) and D3 adds the two events. The status vocabulary C2 must implement, final vs transient: `pending`, `authorize`, `capture` transient; `settlement`, `refund`, `partial_refund`, `chargeback`, `partial_chargeback`, `cancel`, `expire`, `deny`, `failure` final; `fraud_status` (`accept`/`challenge`/`deny`) is a **separate** axis on card only — `challenge` needs `/approve` or `/deny`, which this phase does not expose. Sources: `reference/refund-transaction`, `docs/transaction-status-cycle`, `docs/what-is-chargeback` |
| **D-24** | P19-A3: "Include/Exclude Tax filter toggles" — a *filter* on the dashboard — and a single `tax_ppn_rate` | The owner's clarification 2026-11-08 is a pricing fact, not a view preference: in Indonesian retail a shelf price either **already contains** PPN (`harga sudah termasuk PPN`) or has it **added at the till** (`belum termasuk PPN`), and the two produce different customer totals from the same shelf price. Since 1 Jan 2025 the tariff is 12 % with **DPP nilai lain = 11/12 × harga jual** for general goods (PMK-11/2025), which lands on an effective **11 %**, while luxury goods carry the full 12 %; the rate has moved 10 → 11 → 12-with-DPP-nilai-lain inside four years and the finance minister stated (Dec 2025) no decision for 2026 | `tax_mode` (`exclude` = add-on, `include` = carved out of the tagged price) + `tax_ppn_rate` + `tax_ppn_applied` become **settings** (spec 13), and the *arithmetic* differs: `exclude` → `dpp = taxable lines + service charge`, `ppn = round(rate × dpp)`, `total = dpp + ppn ± rounding`; `include` → the tagged amounts stay the total, `ppn = round(rate/(1+rate) × inclusive taxable)` and `dpp = total − ppn`. **Adding 11 % on top of an inclusive price is an 11 % over-collection at the counter**, so B8 owns the branch and B2 asks B8 for the figures rather than accepting a mode or a total from the browser (D-7). Every `pos_transactions` row stores the **rate, mode and DPP it was computed with**, so a receipt reprint and a masa-PPN report read history, never today's setting — the reason A3's toggle stays a filter (it changes what the *report* shows, not what the *sale* was). A voucher on an inclusive basket reduces DPP and PPN together (the discount comes off the tagged price), and the report's taxable/non-taxable split comes from `pos_transaction_items.is_taxable`. Recorded as **ADR-038**. Sources: `pajak.go.id` (PMK-11/2025, DPP nilai lain), `klc2.kemenkeu.go.id`, `setkab.go.id` (12 % for luxury only), `muc.co.id`, `pajakku.com` (2026: no decision yet) |
| **D-25** | (unstated) what "pembulatan" is | The owner's receipt list has a **Pembulatan** line, which is how Indonesian retail receipts reconcile a percentage-derived total to the whole rupiah a customer can actually pay — IDR has no circulating minor unit, and the tax itself is rounded to the nearest rupiah on the faktur. `decimal(12,2)` in the schema and `(int) round()` at the gateway (D-19) already disagree with the drawer otherwise | `pos_transactions.rounding_adjust` `decimal(12,2)` — **a signed balancing line with `abs(x) < 1`**, whose only job is that `sum(printed lines) == printed grand total` exactly. Rounding happens once, at the document level, so a 20-line basket does not accumulate 20 half-cent decisions; the **tax is computed on the un-rounded DPP** and the line-level `line_tax` is an allocation, never a sum of independently rounded figures. `total` after rounding is what `CompletePosTransactionAction` uses for cash/change (the drawer never chases a fraction), what the receipt prints, and what Midtrans receives as the integer. Two rules the tests pin: the adjust line can never be ≥ 1 rupiah, and removing it must not change the payable total of a basket whose lines are already whole rupiah. |
| **D-26** | P19-A1/B4: "biaya layanan (kalau ada)" | **There is no service-charge concept anywhere in the schema** — not on `products`, not on `pos_transactions`. The three places it could live are wrong for different reasons: as a `Product` line (it would pollute top-seller and stock reports, and stock-tracking makes no sense for a fee), as a negative discount (it would be netted against revenue and reduce points earned the wrong way), or as a hardcoded 10 % (a rate nobody owns) | `pos_transactions.service_charge_total` `decimal(12,2)` default 0 + `service_charge_rate` snapshot, computed server-side in B8 from a `SystemSetting` pair (`service_charge_enabled`, `service_charge_rate`), **included in the DPP** when a service fee is the merchant's own charge (taxable), printed on its own line before the tax, and **excluded from the member-points base** unless the owner says otherwise (points are earned on goods — recorded as **O-13**, because it is a loyalty-policy question and B8 must not answer it silently). It is never confused with a gateway fee, which this store cannot see at all (D-20) |
| **D-27** | D-9 / ADR-037: `mike42/escpos-php`, "images pull GD/ImageMagick" | Measured against the package today: **v5.0 (released 2026-07-01)** requires **PHP ≥ 8.2** (this box is 8.3.36 ✓), `ext-intl` and `ext-zlib` (both loaded ✓ — but they are now deployment requirements, so `composer.json` must not be the place we discover it), and it takes images through **`mike42/gfx-php`**, a pure-PHP image library — so the GD/Imagick claim in D-9 was true of v4 and is **not** a blocker for a logo. v5 also **deprecates `DummyPrintConnector` in favour of `MemoryPrintConnector`** (which is precisely the byte-capture seam B4's assertion needs), and `UsbPrintConnector` is **gone** from the tree: the connector list is Cups, Dummy, File, Memory, Multiple, Network, Rawbt, Uri, Windows | **ADR-037** writes the version down as v5.x + `mike42/gfx-php` (its own transitive dep, no ext) + the two exts, and ADR-037's changelog line names them; the byte-stream test uses `MemoryPrintConnector`. **`UriPrintConnector` understands only `file://`, `tcp://`, `smb://`** — so the `receipt_printer_uri` setting is a URI whose honest values for a USB unit are `file:///dev/usb/lp0` (the `usblp` node, Linux) or a **CUPS raw queue name** through `CupsPrintConnector` (which shells out to `lpr` and validates the queue exists at construct time); there is no `usb://`, and nothing here talks to libusb. A dead queue throws at connector construction, which is the "log it, never fail the sale" path (spec 3) |
| **D-28** | "printer thermal — Eppos EP58M/RPP02" | The generic 58 mm ESC/POS class this unit belongs to is documented by its own manual: **57.5 mm printable width at 203 DPI = 384 dots**, **32 characters per line in Font A (12×24)**, ~42 in Font B, USB (some units add BT/LAN), a cash-drawer kick output at DC 12 V / 1 A, **no auto-cutter** (58 mm thermal units do not carry one), ~90 mm/s, thermal head life ~50 km. None of it is in the repository, and none of it can be verified by CI | The **column budget is a design constraint, not a preference**: the layout B4 builds is 32 columns wide (name ≤ 20, amount right-aligned in 11, 1 separator), the logo is validated at **≤ 384 px wide** before it is rasterised, `cut()` is never called (a 3-line feed and a tear edge instead), and the drawer kick is one `ESC p` on a cash tender only (D-9's no-retry rule stands). The **QR** is the printer's native `GS ( k` raster — a 384-dot-wide code printed on 58 mm paper is what a phone scans across a counter, and its scannability is deferred runtime evidence like the paper itself. Device identity: RPP02 shows up as a USB printer handled by `usblp` (Linux) or a CUPS raw queue (macOS dev boxes) — the *exact* vendor/product id, whether this specific unit has a drawer port, and how the header logo prints at 58 mm are **confirmed on the hardware**, not assumed here (§ 7) |
| **D-29** | The owner's dashboard sketch: "uang masuk, uang keluar, total PPN, total transaksi bulanan, export" | Measured against the ledger this phase creates, that sketch is four different questions wearing one word: **till position** (what the drawer should hold — `pos_shifts.expected_cash` vs `counted_cash`), **sales performance** (gross, discounts, returns, by tender and by product), **gateway settlement/reconciliation** (Midtrans orders vs our rows: pending, expired, settled late, unmatched — which C5 exists to answer), and **tax liability** (PPN collected per masa). And "uang keluar" only half exists: the reversible money this schema writes is voids, refunds/chargebacks and the shift float — there is **no expense, petty-cash or purchase table**, so a "uang keluar" card fed by anything else would be invented data (the D-4/D-11 class of finding) | Best practice and the honest subset agree, so the dashboard ships as A3 (today: revenue by tender, discounts, voids/refunds, PPN collected, settled/pending/expired counts) plus **A8** the period report, and the **money-out card is bounded to rows that exist** — voids, refunds, chargebacks, and float paid in/removed at shift open/close — with the label saying so. A supplier/expenses ledger is **O-10** (and is the only thing that would make Profit real, D-11). Nothing here is called a cash-flow statement |
| **D-30** | "ada button untuk export laporan bulanan atau tahunan" | The repository already has exactly this machine, shipped and tested in Phase 10: `AuditExport` + `AuditExportStatus` + `AuditExportRequestAction` (row written **before** the job, dispatch `->afterCommit()`), `GenerateAuditExport` (`chunkById`, a `php://temp` stream so a half-written file never lands on disk, private `Storage` disk, `failed()` marks the row **and unlinks** the partial), `AuditExportDownloadAction` (ownership-checked), `AuditExportReadyNotification`, and `StorageCapacityGuard`. It is CSV only — no spreadsheet or PDF library is installed (`composer.json`: framework, pennant, pulse, sanctum, tinker, flysystem-aws, spatie×3, scramble, vantage) | **B9 copies the shape, not the table**: `pos_report_exports` (requested_by, period_type `monthly|annual`, period, filters json, status, path, generated_at) + `RequestPosReportExportAction` + `GeneratePosReportExport` (on `exports`) + `DownloadPosReportExportAction`, behind `reports.export`, with the notification reused in the same way and the file **never** public. Rejected: reusing `AuditExport` for money data (its row is scoped to the operator's own audit extracts and its filter is `Activity::scopeFiltered()` — a second domain in one table would put every cashier's revenue file behind an audit permission); and a client-side CSV built from the dashboard's own DOM (rejected — it exports what one page fetched, not what the period contains, and it is the client trusting itself, which D-7 forbids). **XLSX/PDF = new package + its own ADR**: O-11 |
| **D-31** | P19-A3: "PPN/PPH breakdown" on one screen | PPN and PPh are not two flavours of the same line. **PPN** is collected *from the customer* and is a liability the moment it is charged; a merchant can only charge it if it is a **PKP**, and a receipt of a PKP carries the NPWP identity the header now prints (A7). **PPh** is the merchant's own income tax — for retail typically final PPh (0.5 % of turnover under the SME regime, with a threshold) or PPh 22 on specific goods, assessed monthly on the business, never on the customer's bill | The dashboard's PPN card is **"PPN terutang periode ini"** = the sum of `tax_total` over *settled* sales in the masa (spec 16) — and the report is a **rekap for the accountant**, never a claim of filing (D-24). PPh appears as an **estimate clearly labelled as such** (rate × gross turnover, "confirm with your accountant"), on the report screen only, **never on a receipt** and never inside a `total`. `tax_ppn_applied = false` is a legal configuration, not a bug: a non-PKP merchant's report says so in words and its PPN column is empty rather than zero-with-a-tick. Whether this installation is a PKP, and its NPWP for the header, is **O-12** |

---

## 4. Data model

| Table | Key columns | Notes |
|---|---|---|
| `products` | `id`, `sku` (unique), `name`, `price decimal(12,2)`, `cost decimal(12,2)` nullable (O-2), `stock_qty` `integer` (nullable = not stock-tracked), `is_active` | Forced by D-4. `stock_qty` is the counter; `stock_movements` is the ledger. Locked with `lockForUpdate()` on the MySQL side, guarded by the conditional update elsewhere |
| `pos_shifts` | `id`, `opened_by` FK→`users`, `opened_at`, `closed_by` nullable, `closed_at` nullable, `opening_float decimal`, `float_in decimal` default 0, `float_out decimal` default 0, `expected_cash decimal`, `counted_cash decimal` nullable, `variance decimal` nullable, `status` enum(`open`,`closed`) | One open shift per cashier is enforced in `OpenPosShiftAction` (the DB half is a unique partial index, MySQL-only — stated, not assumed). `float_in`/`float_out` are the **only** money-out this table can honestly report (a top-up and a drawer removal, each audited, each in `expected_cash`'s arithmetic) — the dashboard's "uang keluar" card is bounded to these plus voids/refunds, because there is no expense ledger (D-29, O-10) |
| `pos_transactions` | `id`, `reference` (unique ≤50 chars — **our** `order_id`, D-18), `pos_shift_id` FK, `member_id` FK nullable, `kind` enum(`sale`,`void`,`refund`,`chargeback`) (D-23), `void_of_id` self-FK nullable, `subtotal`, `discount_total`, `service_charge_total` (D-26), `tax_total`, `dpp` (D-24), `tax_rate` + `tax_mode` + `service_charge_rate` **snapshots** (spec 13), `rounding_adjust` (`abs < 1`, D-25), `total`, `paid_amount`, `change_due` `decimal(12,2)`, `payment_method` enum(`cash`,`qris`,`va`,`edc`) (D-14), `status` enum(`pending`,`paid`,`failed`,`voided`), `settled_at` nullable, `gateway_expires_at` nullable (D-19), `gateway_qr_string` `text` nullable (**the printed QR payload**, D-21 — the only place the EMVCo string lives, so the printer reads it back for a reprint instead of re-calling the gateway), `gateway_va_number` nullable, `currency` | Index `(status, settled_at)` and `(pos_shift_id)` — the two shapes the dashboard and the reconciliation read; index `(status, gateway_expires_at)` — the exact query C5 runs every sweep, so it must not be a scan; index `(settled_at, tax_mode)` — the masa-PPN report filters on it. Money `decimal`, never `float`, whole rupiah at the gateway edge (spec 8, D-19). `total` is **what the customer paid**, `dpp`/`tax_total` are what the mode says about it — the two are never derived from each other at read time (D-24) |
| `pos_transaction_items` | `id`, `pos_transaction_id` FK, `product_id` FK, `quantity integer`, `unit_price`, `discount_total`, `line_tax`, `line_total` `decimal(12,2)`, `is_taxable` bool (D-24), `tax_mode` snapshot | Snapshots the price at sale time — a later `products.price` edit must not rewrite history. `is_taxable` is what makes the report's taxable/non-taxable split a sum instead of a guess |
| `stock_movements` | `id`, `product_id` FK, `type` enum(`sale`,`void`,`adjustment`), `quantity` `integer` (signed), `reference_type`/`reference_id` morph | Ledger only. A correction is a new row (the `member_point_histories` rule) |
| `payment_logs` | `id`, `pos_transaction_id` FK nullable, `gateway`, `environment` enum(`sandbox`,`production`), `gateway_transaction_id` (Midtrans' own id — the one BI-SNAP status polling requires, D-22), `order_reference`, `transaction_status` (the vendor's raw value, never a lossy mapping), `status_code`, `fraud_status` nullable, `payment_type`, `gross_amount decimal(12,2)` nullable, `settlement_time` nullable, `notification_hash` (D-17), `payload json` (**redacted**, D-6/PCI note below), `received_at` | The unique `(gateway, notification_hash)` is the idempotency backstop **D-17 replaces `(gateway, event_id)` with** — Midtrans sends no event id and one transaction legitimately arrives several times. No `fee` column: there is no fee in any payload (D-20). `payload` must never hold a PAN or a full card object — Midtrans sends `masked_card` only, which is storeable; the `signature_key` **is not stored at all**; the payload carries customer phone/email, so it is PII under O-5's retention rule |
| `vouchers` | `id`, `code` (unique, upper), `type` enum(`percentage`,`fixed`), `value` `decimal(12,2)`, `max_discount` `decimal(12,2)` nullable, `min_purchase` `decimal(12,2)` default 0, `quota` `unsignedInteger` nullable (null = unlimited), `claimed_count` `unsignedInteger` default 0, `per_member_limit` `unsignedTinyInteger` default 1, `starts_at`, `expires_at`, `is_active` | **Moved from Phase 18 § 4** with the domain. Money as `decimal`, never `float`. Here the columns finally have an order total to act on — `max_discount` caps the discount and `min_purchase` gates it (the Phase 18 O-4, closed by the move) |
| `voucher_usages` | `id`, `voucher_id` FK, `member_id` FK, `claimed_at`, `reference_type`/`reference_id` nullable morph | Unique `(voucher_id, member_id)` — the idempotency backstop the lock cannot be alone against (D-5). The morph is populated by the POS sale that consumed the code |
| `pos_report_exports` | `id`, `requested_by` FK→`users`, `report` (`period` \| `tax`), `period_type` enum(`monthly`,`annual`), `period` string (`2026-11` / `2026`), `filters` json, `status` enum(`pending`,`completed`,`failed`), `path` nullable, `size_bytes` nullable, `error` nullable, `generated_at` nullable | The **Phase 10 `AuditExport` shape with its own table** (D-30): the row exists before the job, `path` is a private-disk key and never a URL, the download Action checks `requested_by`, and `filters` is what makes the same file reproducible next month. Reusing `AuditExport` itself is rejected — that table means "one operator's extract of the audit trail", and revenue does not belong behind an audit permission |

Settings live in `SystemSetting`, in five families (all of them configuration, not
credentials, so they go through the shared settings whitelist — the distinction D-6 draws):

- **Gateway**: `payment_gateway_driver` (`midtrans`, the only value this phase ships — D-14),
  `payment_gateway_environment` (`sandbox`/`production`), `payment_server_key` (**the only
  credential**, D-16).
- **Tax**: `tax_ppn_applied` (bool — is PPN charged here at all, which is the PKP question,
  O-12), `tax_ppn_rate` (the **effective** rate as a number, not a constant — 11 today,
  because the tariff and the DPP-nilai-lain divisor are policy that has moved three times in
  four years), `tax_mode` (`exclude` = added on top of the shelf price, `include` = already
  inside it, D-24), `tax_rounding` (`nearest_rupiah` — the only value B8 implements, D-25),
  `tax_pph_final_rate` (the merchant's own final-PPh estimate for the report screen —
  never a customer line, never on a receipt, D-31).
- **Service charge** (D-26): `service_charge_enabled`, `service_charge_rate`, and
  `service_charge_taxable`.
- **Receipt / printer** (A7's card, D-27/D-28): `receipt_paper_width` (`58` — the value the
  32-column budget is calibrated to), `receipt_printer_uri` (`file:///dev/usb/lp0`, a CUPS
  queue name, or the sandbox driver), `receipt_store_name`, `receipt_address` (multi-line),
  `receipt_phone`, `receipt_npwp` (printed only when `tax_ppn_applied`), `receipt_logo_path`
  (PNG, private disk, validated **384 px wide max**), `receipt_header_note`,
  `receipt_footer_note` (the thank-you/terms line), `receipt_show_qr`.
- **Retention**: `payment_log_retention_days` and `report_retention_days` (a generated CSV
  containing revenue is not a permanent artifact).

Dropped from this section by the vendor research: `payment_client_key` (only `/v2/token` card
bootstrap reads it, and card is out of scope) and `payment_webhook_secret` (D-16: the signature
is computed **from the server key**, so a second secret would be a second thing to leak for
nothing).

---

## 5. Tasks

### Group A — Cashier UI, gateway settings card, financial dashboard, voucher admin view, flag declaration

Exit gate: fixture render green, zero DB in Blade, all three flags (`pos`,
`payment_gateway`, `vouchers`) declared **and** seeded, Pint clean.

#### P19-A1 — Cashier POS interface
- **Task**: `resources/views/pages/pos/cashier.blade.php`. Product search box, cart
  table, member picker, tender selector (`cash` / `QRIS` / `VA` / `EDC`, D-14), keyboard-shortcut
  legend, receipt preview modal. Vanilla JS cart module under `resources/js/pos/`
  (`@vite`-built, `@nonce`d) talking to the B5/C4 JSON endpoints. Design system: `card
  border-0 shadow-sm mb-4`, footer `bg-body-tertiary border-top py-3`, no `input-group`
  around a validated field.
- **Behaviour**: **the cart is a preview, never the invoice** (D-7) — the server recomputes
  subtotal, tax, discount and change. Keyboard shortcuts are real `<kbd>` labels bound in
  JS, not `onclick=` attributes (CSP). The QRIS branch has a **waiting state**, not a spinner:
  the amount, the countdown from `gateway_expires_at`, "scan the printed receipt" (D-21) and
  the `settlement` transition arriving from the webhook, never from this page's own poll.
  An `edc` tender takes a keyed terminal imprint and calls nothing (D-14); a basket over the
  QRIS Rp 10,000,000 ceiling shows the refusal on the selector, not after the charge (D-19).
  The shift card on the same screen carries open/close **and the float in/out keyer** (B3):
  a `float_out` column with no entry surface is a dashboard card that reads zero forever,
  which is the gateway-fee mistake (D-20) in the opposite direction.
- **Acceptance**: renders empty (no shift, no products, one line, twenty lines); a refused
  action (no open shift, insufficient stock, tender over a channel ceiling) has a visible
  error state, not a silent no-op; the receipt preview modal shows the same fields the printer
  will emit, in the same order, laid out against the **32-column budget** (D-26) — including
  the service-charge line, the PPN line or its "tidak termasuk PPN" note (D-24), and the QR
  payload; **nothing on this page can mark a sale paid** (D-22). The live totals the preview
  reads come from B8's preview endpoint, so the number on the screen and the number on the
  paper are produced by one Action.
- **Tests**: `PosUiRenderTest` (A5).

#### P19-A2 — Payment gateway configuration card
- **Task**: `resources/views/pages/payments/settings.blade.php`, `col-lg-8`: driver select
  (one option, `midtrans` — a select of one is the kill switch's config surface, not an
  abstraction), environment toggle (sandbox/production), the **one** credential input
  (`payment_server_key`) rendered **blank** with "leave blank to keep the stored value", and
  the webhook URL the operator must paste into the Midtrans dashboard (read-only, copied from
  `route()`).
- **Behaviour**: mirrors the mail/S3 cards — secrets are never populated from a read path,
  the environment switch warns before pointing at production, and nothing here computes. No
  webhook-secret field: D-16 removed the concept. The client key is not asked for (D-16).
- **Acceptance**: no credential is in the rendered HTML (asserted against the markup, not
  assumed); the webhook URL is the real signed-free absolute URL; a production toggle
  requires a confirmation step; a saved server key with leading/trailing whitespace is
  refused, because Midtrans rejects a whitespaceed key at the transport layer (the SDK's own
  error text says so) and a cashier must not discover that at the counter.
- **Tests**: `PaymentSettingsUiRenderTest` (A5).

#### P19-A3 — Financial analytics dashboard
- **Task**: `resources/views/pages/payments/dashboard.blade.php` — the **today** screen,
  organised by the four questions a POS operator actually asks (D-29), each card naming the
  window it sums over:
  1. **Cash position (the till)** — expected vs counted, `variance`, float in/out, voids. This
     is the number a closing cashier reconciles, and the only place "uang keluar" is honest:
     voids, refunds/chargebacks and float removed (D-29 — there is no expense ledger).
  2. **Sales performance** — gross settled, discounts (voucher vs line, shown separately),
     service charge, transaction count and average basket, **by tender channel**
     (`cash`/`qris`/`va`/`edc`), top products; refund and chargeback reversals shown as
     reducing the period (D-23).
  3. **Gateway settlement & reconciliation** — settled / pending / expired counts, what C5 is
     still holding, and any short-paid row waiting on a `payments.manage` review (O-8). The
     card that answers "did the money actually arrive", which the customer's app cannot.
  4. **Tax** — **total PPN yang harus dibayar for the period** = the sum of stored `tax_total`
     over settled sales, split by `tax_mode` so an inclusive and an additive basket are never
     added as if they were the same figure, taxable vs non-taxable, and the PPh-final
     **estimate** on its own labelled line (D-31).
  The include/exclude control here stays a **filter over report rows**, not a pricing switch —
  the mode belongs to B8/A7 (D-24), and the previous wording conflated the two.
  **The brief's "gateway fees" card is cut** (D-20 / O-6: no fee field exists in any Midtrans
  payload and there is no fee API), and the Profit card stays **conditional** (D-11/O-2).
- **Behaviour**: every number is a primitive produced by B9's read Action (one Action, so the
  dashboard and the exported CSV cannot disagree); charts are server-rendered tables or the
  existing Pulse blocks, no new chart dependency. A refund or chargeback reversal row reduces
  the period's settled total the way a void does (D-23) — revenue that got clawed back must not
  read as revenue.
- **Acceptance**: a range with zero rows renders zeros rather than an error; the filter changes
  the view, not the arithmetic; the page's query count is pinned (the `PerformanceGuardTest`
  shape — a list must not grow with row count); the PPN card is a **read of stored history**,
  so a row priced under the other mode, or an older rate, still reports its own number
  (spec 13).
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
- **Task**: `tests/Feature/POS/PosUiRenderTest.php` (+ the views named in A2/A3/A7/A8) in the
  `FormViewRenderTest` shape: `view(...)->render()` on fixtures, a sweep asserting no POS
  view reaches for the database, and one assertion that no secret-shaped string appears in
  any rendered POS/payment view — the sweep must cover the receipt-settings card, because
  that page renders the store NPWP and a logo path, and the report screen, because it renders
  a signed download URL that belongs in a route, never in page text (D-30).
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

#### P19-A7 — Receipt template, tax and service-charge settings card
*(added 2026-11-08: the owner asked for a configurable header and a PPN switch — none of it
has a home today)*
- **Task**: `resources/views/pages/settings/receipt.blade.php`, mounted inside the existing
  Settings screen as its own card group (not a new module — these are `SystemSetting` keys and
  the settings UI already owns them): **Printer** (`receipt_paper_width` shown as 58 mm with
  the 32-column note, `receipt_printer_uri`, `receipt_show_qr`), **Header** (store name,
  address lines, phone, NPWP, `receipt_header_note`, logo upload with the ≤ 384 px rule
  enforced in the FormRequest), **Footer** (`receipt_footer_note`), **Tax**
  (`tax_ppn_applied`, `tax_ppn_rate`, `tax_mode` with the two cases spelled out in plain
  English — *"the shelf price already contains PPN"* vs *"PPN is added at the till"* — and
  `tax_pph_final_rate` marked as an estimate input), **Service charge** (enabled, rate,
  taxable).
- **Behaviour**: every field writes through the shared `SystemSettingsUpdateAction` whitelist
  (these are configuration, not credentials — the D-6 distinction); the logo is a file upload
  to the private disk with the same storage guard the rest of the settings use, and the card
  prints a **live 32-column preview** of the header block from the values as typed (a Blade
  partial rendering the posted model, no printer involved).
- **Acceptance**: no `tax_mode` change touches an existing row (the snapshot rule, spec 13 —
  asserted by pricing one basket, flipping the mode, and reprinting the stored row);
  `tax_ppn_applied = false` hides the PPN line and the NPWP line **and** empties the report's
  PPN column rather than zeroing it with a tick (D-31); an oversized or non-PNG logo is refused
  by the Request, not truncated at print time; the address block renders multi-line with the
  32-column wrap; every field survives a cached-config reload.
- **Tests**: `ReceiptSettingsUiRenderTest` (A5 sweep) + `ReceiptSettingsPersistenceTest` (B-side
  round trip through the whitelist).

#### P19-A8 — Monthly / annual report screen with the export button
*(added 2026-11-08 by the dashboard research — D-29/D-30)*
- **Task**: `resources/views/pages/reports/index.blade.php` + `_filters.blade.php` (the
  `activity-logs/_filters` shape): a **period picker** (month, year, `monthly`/`annual`), the
  per-masa table (transactions, gross, discounts, service charge, DPP, PPN, non-taxable,
  voids, refunds/chargebacks, cash vs QRIS vs VA vs EDC, estimated PPh), and two buttons —
  **"Export CSV"** for the period and **"Export PPN rekap"** for the masa — each hitting B9's
  request Action. The screen also lists the viewer's own recent exports with their status and
  a download link, exactly the way the audit page does, because that is the question an
  operator asks next ("is it done yet?").
- **Behaviour**: the file is **generated by a queued job**, so the page never builds a
  spreadsheet in the browser and never exports what the current page fetched (D-30); the
  download link is ownership-checked; a `pending` row shows a status, not a spinner that
  cannot resolve; nothing on the page claims a filing has happened (D-24).
- **Acceptance**: the numbers on this page and the rows in its CSV come from the same Action
  and are asserted equal against one fixture population; a period with no rows exports a valid
  file with a zero footer; `reports.export` alone cannot open another user's file; the page
  renders with the app in maintenance and with no exports at all.
- **Tests**: `ReportUiRenderTest` (A5 sweep) + `PosReportExportTest` (E1).

### Group B — POS engine, shifts, stock, voucher domain, printing

Exit gate: migrate/rollback exit 0 on SQLite; Actions ≥ 95 % covered; `ActionNamingConventionTest`,
`NoInlineFqnTest`, `JobConventionTest` green.

#### P19-B1 — Migrations, models, factories
- **Task**: § 4 tables (including `products`, D-4, and `pos_report_exports`, B9's ledger of
  generated files) + `App\Models\{Product,PosShift,PosTransaction,PosTransactionItem,StockMovement,PaymentLog,PosReportExport}`
  with `Auditable`, casts to `decimal:2` for money, factories for each. `PosTransaction`
  gets the `reference` unique. The money columns the printer/report pass added
  (`service_charge_total`, `dpp`, `tax_rate`, `tax_mode`, `service_charge_rate`,
  `rounding_adjust`, `float_in`, `float_out`) ship in this migration, not in a later
  one — the column exists before anything writes it. The two `voucher*` tables of § 4 and
  their models ship in **B6** — same group, split by domain (spec 11).
- **Acceptance**: `migrate` and `migrate:rollback` exit 0; a `void` row cannot exist without
  `void_of_id`; a sale with no shift is refused by the Action, not by the DB alone.
- **Tests**: `PosModelTest`.
- **Docs**: `docs/base/data/` gains the POS schema page; `naming-conventions.md` gains the
  `Pos` domain (D-13).

#### P19-B2 — `ProcessPosTransactionAction` + `CompletePosTransactionAction`
- **Task**: split by tender, because Midtrans is asynchronous (D-22) and the brief assumed a
  sale is settled when it is rung up.
  `app/Actions/V1/Pos/ProcessPosTransactionAction` — one `DB::transaction`:
  require an open shift → **recompute every line from `products`** (D-7) → hand the recomputed
  basket to `CalculateSaleTotalsAction` (B8) and store **its** numbers verbatim — this Action
  adds no arithmetic of its own, including no second rounding rule (spec 13: the tax rate,
  mode and service-charge rate are snapshotted onto the row here, once) → if the cashier
  entered a code, validate it with `ValidateVoucherAction` (B6) and apply the capped
  discount, claiming it with `ClaimVoucherAction` (B6, populating the usage row's
  `reference_*` morph); an invalid code refuses the sale with the contract's error code →
  insert the transaction + items (with `reference`, `payment_method`, `gateway_expires_at`)
  → then either **complete it inline** (`cash`, `edc`: the tender is already in the drawer or
  out of band) by calling `CompletePosTransactionAction`, or **leave it `pending`** (`qris`,
  `va`) for C2/C5 to complete. The `pending` branch **prints straight away**: the QRIS code the
  customer has to scan lives on that paper (D-21), so the QR-bearing slip is a process-time
  print. Completion prints only for the tenders that were rung up in front of the printer;
  a settlement arriving from the webhook or the sweeper does **not** auto-print — the cashier
  reprints the paid copy from A1 when the customer asks for it.
  `app/Actions/V1/Pos/CompletePosTransactionAction` — the **one** completion path: any point
  redemption → `lockForUpdate()` + conditional stock decrement (refuse a negative) → flip the
  row to `paid` + `settled_at` → compute change → earn points via `EarnMemberPointsAction` →
  audit `pos_transaction.completed`. Printing is **not** in this Action: C2 and C5 complete a
  sale in a request that has no cashier and no paper trail to hand over, so the print belongs
  to the caller that stands at the register. Called by B2 (cash),
  by the webhook (C2) and by the sweeper (C5) — **not** a third implementation of the same
  rules (spec 7's one set of Actions, now across three triggers).
- **Acceptance**: an oversell is impossible **at completion** (a second concurrent sale of the
  last unit is refused — proven by the conditional update, D-5); the amount handed to C1 as
  `gross_amount` is B8's total for the row and nothing else — in exclusive mode it is
  subtotal + service charge + PPN, in inclusive mode it **is** the shelf total with the PPN
  already inside it (D-24: charging both would over-collect a tax the customer never agreed
  to); a `pending` gateway sale
  **reserves nothing**, so the ring-up states available stock as a warning, not a hold, and a
  completion that fails on stock leaves a row marked paid-but-unfulfilled that
  `payments.manage` can see (the consequence of D-22, stated rather than hidden); the shift's
  expected cash moves by exactly the change arithmetic **only for cash tenders** (a QRIS sale
  never touches `expected_cash` — it lands in the payout, D-20); a stock refusal leaves no
  completed row; the points ledger's `balance_after` still equals the member's balance; the
  voucher is claimed by the **process**, not the completion, so an expired QRIS sale does not
  silently give its code back (D-11 of Phase 18's void question, answered here).
- **Tests**: `PosTransactionTest` (E1) — including cash and gateway tenders through the same
  completion Action with the same fixture cart.

#### P19-B3 — Shift open/close Actions
- **Task**: `OpenPosShiftAction` (one open shift per cashier, opening float validated) and
  `ClosePosShiftAction` (expected cash = opening float `float_in` + cash sales − cash voids −
  change − petty-cash payouts `float_out`; `counted_cash` in, `variance` computed and audited).
  The two float columns exist for the only honest answer to the owner's "uang keluar" question
  (D-29): money leaving the drawer is a **keyed, per-shift, audited entry**, not a balance the
  dashboard invents. `RecordPosShiftFloatAction` is what makes that sentence true — an
  `in`/`out` amount with a note, applied to the **open** shift only, written inside
  `DB::transaction` and audited as `pos_shift.float_adjusted`, which is the ledger for this
  (no new table: the audit row carries amount, direction, note and who keyed it). It moves
  `expected_cash` the moment it lands — a payout the close does not know about is a variance
  the cashier will be blamed for. No category table, no approval chain, no receipt attachment:
  that is the expense ledger, and it stays out of this phase (O-10).
- **Acceptance**: a second open on the same register is refused with a named reason;
  a close with an unsettled `pending` transaction states what happens to it, **by tender**:
  a `pending` **cash** row is a mistake and is voided-by-hand (never silently swept), while a
  `pending` `qris`/`va` row is *normal* at closing time and closes **with** the shift — the
  money is not in the drawer, so it never touches `expected_cash` or the variance, and C5
  may still settle it after the register is shut (the webhook does not know about shifts,
  D-22). The close therefore prints/returns an "unsettled at close" list so a cashier hands
  over what is still live instead of pretending it is zero. Decided and tested, not left
  implicit. A float entry against a **closed** shift is refused (it would rewrite a handover
  someone already counted), and a `float_out` larger than the drawer is allowed but drives the
  variance negative instead of being silently clamped — the cashbox is what it is.
- **Tests**: `PosShiftTest` — including the close-with-a-pending-QRIS case and the
  float-entry-moves-expected-cash case.

#### P19-B4 — ESC/POS receipt printing on the EP58M / RPP02
- **Task**: **ADR-037 + a `changelog.md` line first** (D-9, D-27), then
  `composer require mike42/escpos-php:^5.0` behind **one** class,
  `App\Support\Receipt\ReceiptPrinter`, which takes the stored transaction **plus** B8's totals
  array and the header/footer/logo values from A7's settings keys, composes the receipt model
  for the paper width, and writes bytes through the connector named by `receipt_printer_uri`.
  Nothing else in `app/` may touch
  `Mike42\Escpos\*`. The printer reads **snapshotted** rate/mode values off the row (spec 13),
  never the live settings — a reprint from December must look like the sale it came from even
  after the rate changed in January.
- **Connector, per the hardware (D-27/D-28)**: the EP58M/RPP02 is a **USB** unit, so the
  production paths are `FilePrintConnector` on the `usblp` device node
  (`file:///dev/usb/lp0`, Linux) or a **CUPS raw queue** via `CupsPrintConnector` (macOS dev
  boxes; it validates the queue name at construction and shells out to `lpr`). There is no
  `usb://` scheme in `UriPrintConnector`, and v5 deleted `UsbPrintConnector` — a breakdown that
  promised libusb would be wrong on day one. `receipt_printer_uri` empty or `memory` =
  the sandbox, which renders without hardware.
- **Layout budget (the whole reason the model class is named for the paper)**: **32 columns**
  at Font A on 58 mm, 384 dots wide. Header: logo (`receipt_logo_path`, rasterised at
  **≤ 384 px** through `mike42/gfx-php`, PNG — no GD dependency), store name, address lines,
  phone, and the NPWP line **only when `tax_ppn_applied`** (D-24). Body: item lines
  (`name ≤ 20 cols` + `qty × price` + right-aligned `line_total` in 11 cols, with the stated
  wrap/truncate rule), per-line discount when the line carries one. Totals block, in the order
  the owner gave it and in the order the money actually resolves: **subtotal → service charge
  → total discount (voucher + line) → PPN (or the "PPN termasuk harga" note, D-24) →
  pembulatan → total bayar → tender → change → member + points footer → QRIS QR (native
  `GS ( k`, `receipt_show_qr`) → footer note → 3-line feed**. **`cut()` is never called** —
  58 mm thermal units have no cutter, and a `MethodNotFoundException` at the counter is a
  printer failure that would have been invisible in CI.
- **Behaviour**: called **after commit**, outside the money transaction (spec 3); a printer
  failure — including a connector that throws at construction because the queue name is wrong
  — is logged and surfaced as "receipt not printed — reprint available", **never** as a failed
  sale. The cash-drawer kick is one `ESC p` on a cash tender, never retried, and its existence
  on this unit is confirmed on the hardware (§ 7). **The print is synchronous, in the
  register's own request** — not a queued job: the printer is a USB device attached to the
  machine behind the counter, while a queue worker is another process that may run on another
  box and would write bytes into a device node that is not there. So no new `QueueName`
  constant and no print job. The cost is honest and bounded: ~90 mm/s means a long receipt is a
  couple of seconds inside an already-open request (§ 7 defers measuring the worst case on the
  unit), and the escape hatch for a printer that is off or out of paper is the reprint button,
  not a queue retry.
- **Acceptance**: the emitted **byte stream** is asserted for a known cart through
  `MemoryPrintConnector` — header order, a 40-character product name wrapping to the stated
  rule rather than overflowing, the `abs(rounding_adjust) < 1` line appearing only when it
  changes the payable total, the PPN line present in `exclude` mode and the inclusion note
  instead in `include` mode, the QR block byte-for-byte from the stored `qr_string`, and a
  reprint of the same transaction producing **the same bytes**; `DummyPrintConnector` (deprecated
  in v5) appears nowhere in the new code.
- **Tests**: `ReceiptPrintTest` (byte-level, 32-column fixtures).
- **Deferred**: that the paper comes out right, that the logo is dark enough at 58 mm, that the
  QR scans from a phone across a counter, and whether this unit has a drawer port — § 7.

#### P19-B8 — Tax, service charge and rounding engine
- **Task**: `app/Actions/V1/Pos/CalculateSaleTotalsAction` — **the only place money is
  computed in this phase**, called by both `ProcessPosTransactionAction` (B2) and the cart
  preview endpoint (B5), so the preview and the invoice can never disagree by construction.
  Inputs: the recomputed lines from `products` (D-7), the voucher's capped discount (B6), and
  the settings `tax_ppn_applied` / `tax_ppn_rate` / `tax_mode` / `service_charge_*`.
  Output: one flat array of primitives — `subtotal`, `discount_total`, `service_charge_total`,
  `dpp`, `tax_rate`, `tax_mode`, `tax_total`, `rounding_adjust`, `total`, and the per-line
  `is_taxable` + `line_tax` allocation.
- **Arithmetic, both modes (D-24)**: `exclude` → `dpp = taxable lines − their discount +
  taxable service charge`, `tax_total = round(rate × dpp)`, `total = dpp + tax ± rounding`;
  `include` → the tagged amounts **are** what the customer pays, so
  `tax_total = round(rate/(1+rate) × inclusive taxable)`, `dpp = total − tax_total`, and the
  grand total does not move. Rounding happens **once, at the document** (D-25); line tax is an
  allocation, never a sum of independently rounded figures.
- **Snapshot, then never re-derive (spec 13, ADR-038)**: the row stores the rate, mode, service
  charge rate and DPP it was computed with. A reprint, a void, a refund notification and the
  masa-PPN report all read those columns — a settings change or a new government rate must not
  rewrite a receipt a customer already holds, and it is the reason `tax_rate` is a column at
  all.
- **Acceptance**: `include` mode proves the **counter doesn't over-collect** — the same basket
  priced at Rp 111,000 inclusive yields `total 111000`, `tax 11000`, `dpp 100000`, and adding
  11 % on top is asserted to be impossible; a mixed taxable/non-taxable basket sums tax over the
  taxable lines only; the rounding line can never reach 1 rupiah and disappears when the basket
  is already whole; a tampered client-side total is refused (D-7); the voucher discount on an
  inclusive basket reduces DPP and tax together; and `CalculateSaleTotalsAction` is a **pure
  read of its inputs** — it writes nothing, so the cart preview can call it a hundred times.
- **Tests**: `SaleTotalsTest` (E1) — table-driven over both modes × taxable mix × rounding
  edges, with exact `decimal` comparisons.

#### P19-B9 — Period aggregates + the report export
- **Task**: `app/Actions/V1/Reports/{GetFinancialPeriodAction,BuildTaxSummaryAction,RequestPosReportExportAction}`
  + `app/Jobs/GeneratePosReportExport` (on `exports`) +
  `app/Actions/V1/Reports/DownloadPosReportExportAction` + `App\Models\PosReportExport` —
  **the Phase 10 export shape copied, not re-invented** (D-30): row before the job, dispatch
  `->afterCommit()`, `chunkById` over the period, a `php://temp` stream so a truncated CSV
  never lands, private disk, `failed()` marking the row and unlinking the partial, a ready
  notification, an ownership-checked download, `StorageCapacityGuard` honoured, retention from
  `report_retention_days`.
  **CSV, one row per ledger line plus a totals footer** — never a re-derived number, so the
  file and the screen add up to the same figures because they read the same Action (the method
  `AuditExportRequestAction` documents). The **tax report** (`report = tax`) emits per masa:
  taxable DPP, PPN collected, non-taxable sales, voids/refunds/chargebacks **reducing** it, and
  the PPh final **estimate** column labelled as an estimate (D-31).
- **Acceptance**: a monthly and an annual period return their own windows (one grouped
  aggregate per bucket — the `PerformanceGuardTest` query-count shape, not one query per day);
  an empty period exports a file with headers and a zero footer, not a 500; **a caller cannot
  download another user's file** and cannot read a period outside their permission; the CSV
  totals equal the dashboard's numbers for the same period, asserted from one fixture
  population; a `payment_gateway`-off install can still export (this is a ledger read, not a
  gateway call); the job clears `JobConventionTest`.
- **Tests**: `FinancialPeriodTest`, `PosReportExportTest` (E1) + `JobConventionTest`.
- **Docs**: `docs/base/features/payments.md` § Reports (what each period means, that the
  report is a **rekap** and not a filing, D-24) and `docs/base/infrastructure/queue.md` gains
  the job to its table.

#### P19-B5 — POS controllers, routes, sidebar
- **Task**: thin `app/Http/Controllers/Web/V1/{PosCashierController,PosTransactionController,
  PosShiftController,PosReportController}` + the `Api/V1/Pos/*` twins +
  `app/Http/Resources/Api/V1` resources; route groups with `feature:pos` + `can:` at the route;
  the `AppMenuComposer` entries (A4b, per D-2 of Phase 18) — which now carry **Reports**
  (`reports.view`, pointing at A8) as its own entry, not a tab under POS; Scramble annotations
  per `docs/base/api/api-architecture.md` § API Conventions.
  The JSON endpoints A1/A7/A8 need and that have no other owner:
  `POST /pos/preview` (the cart totals, served by `CalculateSaleTotalsAction` — B8's only
  write-free caller), `POST /pos/transactions/{transaction}/print` (reprint the stored
  snapshot through B4), `POST /pos/shifts/{shift}/float` (B3's keyer), and the two report
  routes of A8 (period read + export request, whose download route is the ownership-checked
  B9 Action).
- **Acceptance**: web/API capability diff clean by capability; no controller contains
  `DB::transaction` or inline `validate()`; `route:list` shows a gate on every write;
  `/pos/preview` provably writes nothing (a row count taken before and after) and returns
  B8's primitives, so the screen cannot be the place a second rounding rule grows; the reprint
  route re-emits the **stored** snapshot and is asserted not to call the gateway or recompute
  against today's tax settings (spec 13).
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

### Group C — Gateway integration, webhook shield, credentials, reconciliation

Exit gate: every gateway call is fake-able (`Http::fake`, which is exactly what D-15 buys)
and the webhook is provably single-settle, out-of-order-safe and signature-gated.

#### P19-C1 — `CreatePaymentOrderAction`
- **Task**: `app/Actions/V1/Payment/CreatePaymentOrderAction` — generate the deterministic
  `reference` **before** the call (it is the `order_id` and the 406 shield, D-18), validate
  the tender against the channel limits (QRIS ≤ Rp 10,000,000, the VA's floor, whole-rupiah
  total — D-19) **before** any socket opens, then `POST /v2/charge` through
  `App\Support\Gateway\MidtransGateway` with `payment_type` `qris` or `bank_transfer`,
  `transaction_details{order_id, gross_amount: int}` and `custom_expiry` (15 min for QRIS,
  never shorter — D-19), `timeout(5)` / `connect_timeout(2)` / `retry(3, …, $when:
  connection-or-timeout-or-5xx only)`, wrapped by `GatewayCircuitBreaker` (D-3) and sent with
  the `Idempotency-Key` header carrying the reference (D-18: sent, never relied on).
  Persist from the response: `gateway_transaction_id`, `transaction_status`, `status_code`,
  `expire_time` → `gateway_expires_at`, and the tender payload — **`qr_string` for QRIS,
  `va_numbers[].va_number` for VA** — on the transaction, not in the session.
  A retry after a timeout is a `GET /v2/{order_id}/status` **first** (5xx is ambiguous:
  created-or-not is unknown, D-18); a `406` means the reference is already live at Midtrans,
  which resolves to the stored row, never to a new one.
- **Acceptance**: an open breaker refuses before any socket is opened and answers a
  distinct error code (`docs/base/api/error-contract.md`) so the cashier falls back to
  cash; a retried call carries the same `reference`; a **4xx does not retry** and marks the
  row `failed`; **201 is not a failure** — it is the created-pending answer this whole flow
  expects, and the tests assert attempt counts with `Http::fake` sequences (201 after a
  connection error, 406 after a real duplicate); the pending row survives a failed call and
  is auditable; the `qr_string` never reaches a log line or the audit payload.
- **Tests**: `PaymentOrderTest` (with `Http::fake`, asserting attempt counts and the exact
  charge body against a fixture cart).
- **Docs**: `docs/base/features/payments.md` (created in B7's style by this row) states the
  endpoint set, the status vocabulary (D-23) and the two credential-free rules: the signature
  formula and the 406/201 semantics.

#### P19-C2 — `ProcessPaymentWebhookAction`
- **Task**: verify the `signature_key` — `hash_equals(hash($algo,
  $order_id.$status_code.$gross_amount.$serverKey), $signature_key)` with `$gross_amount`
  read **as the payload's string**, `$algo` a named constant pinned by a sandbox-proven
  fixture (D-16) — **before any DB read** → resolve by `order_reference` →
  `DB::transaction` with `lockForUpdate()` **and** the atomic `WHERE status = 'pending'`
  update → insert the `payment_logs` row keyed on `notification_hash` (D-17) →
  branch on `transaction_status` (the D-23 table): `settlement` (and `capture-pending`→
  ignore) completes the sale through `CompletePosTransactionAction` (B2) or activates the
  membership subscription through Phase 18's Action; `expire`/`deny`/`cancel`/`failure`
  close the pending row; `refund`/`partial_refund`/`chargeback`/`partial_chargeback` on an
  already-settled row insert a **reversing** transaction (`kind`, D-23) and do **not** un-settle
  anything; a `pending` arriving after `settlement` is logged and dropped (out-of-order, D-17) →
  audit `payment.webhook_settled` / `payment.failed` / `payment.refund_recorded` /
  `payment.chargeback_recorded`. Compare `payment_amounts` and the settled `gross_amount`
  against the expected total before completing: a mismatch is recorded, **not** completed
  (O-8). Response budget well under Midtrans' ~15 s: any heavy follow-up is dispatched
  after commit.
- **Acceptance**: a duplicate delivery settles exactly once (second delivery is a 2xx
  no-op, not a 500 — a 500 would buy five retries, D-17); a bad signature is refused before
  any query runs, answers **401**, and is logged **without** the signature or the key; an
  unknown `order_reference` answers the same shape as a bad signature (no enumeration
  oracle); a `settlement` that arrives twice from two different notification deliveries
  (out-of-order + retry) still moves stock and points once; a short-paid VA is held, not
  completed; the refund notification reduces the period's revenue in A3's read.
- **Tests**: `PaymentWebhookTest`, `PaymentWebhookRaceConditionTest`,
  `PaymentWebhookSignatureTest` (E1).
- **Docs**: `docs/base/security/rate-limiting.md` gains the new limiter row.

#### P19-C3 — `UpdatePaymentSettingsAction`
- **Task**: the **only** writer of the one credential key (D-6, D-16): validate (non-empty when
  supplied, no surrounding whitespace — Midtrans rejects a whitespaceed key outright), compare
  the submitted plaintext against `AppServiceProvider::storedSecret()` and re-`encrypt()` only
  on a real change, strip the secret from the audit payload, then rebind
  `config('payment.*')` **after commit** through `AppServiceProvider::bindPaymentConfig()`
  — the `bindMailConfig()` shape, never at boot. `MidtransGateway` reads `config('payment.*')`
  **at call time**, which is the property D-15 refuses the vendor SDK over: a static
  `$serverKey` cannot be rebound per request without leaking across jobs in a worker.
- **Acceptance**: a blank submission keeps the stored ciphertext; `GET /api/v1/settings`
  and `SystemSetting::getAll()` cannot see this key; the audit row records *that* a key
  changed, never *what*; a driver or environment switch takes effect at runtime without a
  redeploy (proven under a cached config, the P17-C2 method), and a queued job dispatched
  before the switch still charges against the environment it was created in — asserted by a
  test, not by a comment.
- **Tests**: `PaymentSettingsPersistenceTest` + the standing `SettingsPentestTest` shape
  (the credential-disclosure probe is the one that found Phase 9's finding #1).

#### P19-C4 — Payment controllers and the webhook route
- **Task**: `app/Http/Controllers/Web/V1/{PaymentGatewayController}` + `Api/V1/Payment/*`,
  the sidebar entries (A4b), and the public route `POST /api/v1/payment/webhook/midtrans`:
  **no** `auth:sanctum`, no `web` group (D-12 — so no CSRF `except` entry), its own named
  `RateLimiter::for('payment-webhook')` bucket, `feature:payment_gateway`, a bounded
  request size, and a `200`/`4xx` answer that never echoes the payload. The driver segment
  stays: one vendor today, but the URL is what an operator pastes into the Midtrans dashboard
  and renaming it later is a support conversation.
- **Acceptance**: the route is outside the `web` middleware stack (asserted, not assumed);
  the limiter returns the contract's 429 envelope; a `GET` to it is 405; the generated
  OpenAPI document carries it; an unauthenticated caller cannot read a transaction through it
  (it answers nothing about the payload).
- **Tests**: `PaymentWebhookRouteTest`; `ApiDocumentationTest`; `CsrfProtectionTest`
  re-run unchanged (D-12's "do not fix the empty except list" is checked by it staying green).

#### P19-C5 — Stale-payment reconciliation sweep *(added 2026-11-08 by the Midtrans research)*
- **Task**: `app/Console/Commands/ReconcileStalePayments` (`payment:reconcile-stale`, scheduled
  every 5 minutes in `routes/console.php`) dispatching
  `app/Jobs/ReconcileStalePaymentsJob` on the `sweeps` queue through
  `App\Support\QueueName`, following `docs/base/infrastructure/queue.md` § *The standard every
  job follows* in full (named queue via `onQueue()`, explicit `$tries`/`$timeout`/
  `$maxExceptions`/`backoff()`, `failed()` logging event + exception + `request_id` through
  `ReadsCorrelationId` — never from the container). It selects `pos_transactions` still
  `pending` with `payment_method` in (`qris`,`va`) and `gateway_expires_at` past (a bounded
  batch, `(status, gateway_expires_at)` index from § 4), calls `GET /v2/{order_id}/status`
  (and by `gateway_transaction_id` for any BI-SNAP-style channel, D-22) and pushes each result
  through **the same** `ProcessPaymentWebhookAction`'s transition core (D-22: one completion
  path, two triggers) — never a second copy of the settle logic.
- **Why it exists**: a payment that fails inside the customer's app stays `pending` and is
  **never notified** (D-22), so without this row the counter's only record of a dead QRIS sale
  is a cashier staring at a waiting screen and a shift that cannot be closed honestly.
- **Acceptance**: a `pending` whose gateway status is `settlement` completes (stock, points,
  audit — proven by the same assertions as the webhook path); one that is `expire` closes as
  `failed`; one still `pending` past expiry is re-checked on the next sweep and never
  double-completed (the atomic `WHERE status = 'pending'` update makes the sweep safe against
  a webhook that arrives mid-run); the job survives a worker restart and a duplicate dispatch
  (idempotent by construction); `JobConventionTest` covers it by reflection with the rest of
  `app/Jobs`.
- **Tests**: `PaymentReconcileTest` (E1) + `JobConventionTest`.
- **Docs**: `docs/base/infrastructure/scheduling.md` gains the row; `docs/base/features/payments.md`
  states that the webhook is *a* trigger and the sweeper is the backstop, and that neither is
  trusted alone.

### Group D — Enforcement

#### P19-D1 — RBAC
- **Task**: `POS`/`PAYMENTS`/`VOUCHERS`/`REPORTS` in `app/Support/PermissionCatalog.php` —
  `pos.access`, `pos.manage`, `pos.void_transaction`, `payments.view`, `payments.manage`, the
  voucher domain's own pair moved from Phase 18 (`vouchers.view`, `vouchers.manage`), and the
  pair the report screen needs: `reports.view` (open A8, read the period) and `reports.export`
  (request a file — a queue slot and a disk write, so it is a separate gate, not a flag on the
  same button) —
  seeder
  prune/create/assign, role matrix, every write route carrying `can:`, FormRequest
  `authorize()` naming the same permission.
- **Note the shape**: `pos.access` is deliberately coarser than `pos.manage` because a
  cashier is not a manager, and this is the repo's first **non-admin** permission on a
  money write path — the role matrix has to say which role holds it (recorded as O-3).
- **Acceptance**: a `pos.access`-only user 403s every void, settings and dashboard route;
  a `reports.view`-only user reads the period table and 403s the export route, and holding
  `reports.export` without `reports.view` can request a file but never open the screen — the
  two gates are proven independent, not nested; neither can read another user's export (that
  is B9's ownership check, not the gate, and the test says which);
  a zero-permission user 403s the cashier page itself and every voucher screen on both
  channels; `payments.manage` alone cannot ring a sale; the reconciliation sweep (C5) needs
  **no** permission at all — it is a scheduled job whose `causer` is `SYSTEM`, and giving it
  a permission would imply a human can trigger it from a screen.
- **What D1 deliberately does not add**: `payments.refund`. Midtrans' refund API is disabled
  on a POS transaction until the account is commercially activated (D-23), and this phase
  only *records* an arriving refund/chargeback notification — it never initiates one. A
  permission for an action no code performs is a half-adopted gate (the `AGENTS.md`
  failure), so it ships with whoever builds the refund screen. For the same reason A7 adds
  no permission: it writes `SystemSetting` keys through the existing
  `SystemSettingsUpdateAction` whitelist, already gated by `settings.manage` — a
  `receipt.manage` would be a second gate on one screen. The printer is a setting, not a
  resource.
- **Tests**: `PosAuthorizationTest`, `PaymentAuthorizationTest`; catalogue-drift guards updated.

#### P19-D2 — Feature-flag enforcement
- **Acceptance**: `pos` off → **403** (not 404, D-1) on both channels + the sidebar entry
  drops for superadmin too; `payment_gateway` off → the QRIS/gateway path 403s while the
  **cash** path still completes (this is the assertion that proves the two-flag decision in
  A4a); `vouchers` off → 403 on every voucher screen on both channels and its own menu
  entry drops — killing vouchers must NOT take the POS or membership down with it (the
  three-flag independence, spec 11); an undeclared slug fails closed.
- **The one place the kill switch must not reach: money that already moved.** The webhook
  route carries `feature:payment_gateway` (C4) so a flagged-off gateway refuses *new* charges,
  and Midtrans answers that 403 with its own retry ladder (D-17) — which is survivable only
  because **C5 is not flagged**: the sweeper keeps closing out whatever is still `pending`,
  so a customer who paid during an outage gets settled by the backstop rather than left as
  uncollected revenue with an expired QR. The assertion that pins this is a test, not a note:
  `payment_gateway` off + a `pending` row past `gateway_expires_at` whose gateway status is
  `settlement` → the sweep completes it. A gateway-side refund of that money is a manual
  act (§ 7), and that asymmetry is the reason the flag is a *charge* switch.
- **Tests**: the per-slug flag middleware shape, plus the cash-survives-gateway-kill case
  and the sweep-settles-through-a-killed-flag case.

#### P19-D3 — Audit events
- **Task**: thirteen events — `pos_shift.opened`, `pos_shift.closed`,
  `pos_shift.float_adjusted` *(added with the float columns: money leaving a drawer with no
  event is an accusation waiting to happen)*, `pos_transaction.created`,
  `pos_transaction.completed` (the B2 split: the gateway path completes a row it did not
  create), `pos_transaction.voided`, `payment.order_created`, `payment.webhook_settled`,
  `payment.failed`, `payment.refund_recorded`, `payment.chargeback_recorded` (D-23: both land
  on an already-settled row and would otherwise read as revenue), `payment.settings_updated`,
  `voucher.claimed` (travelling with the voucher domain from P18-D3) — each written inside its
  Action's transaction, each with `causer = 'SYSTEM'` where the actor is the webhook or the
  C5 sweeper (the scheduler is a writer of money rows with no human behind it; an audit trail
  that names a staff member for a machine's settle is a false accusation, not a record).
- **Note**: the brief's four events miss shift-close, void and settings-change; a money
  module whose void and float-variance writes have no trail is the thing an owner asks
  about at 2 a.m. — and the count is thirteen, not fourteen, on purpose: **A7 writes no new
  event** (its fields ride the existing `system_setting.updated`, because they are
  `SystemSetting` keys and that Action already audits them), and **B9 ships the export with no
  audit event either**, exactly like the Phase 10 export it copies — `AuditExportRequestAction`
  logs a retry and writes no trail, because the row in `pos_report_exports` *is* the record of
  who asked for what. Inventing events for those two would be trail-padding.
  `payment.settings_updated` must carry **no** credential value (C3) — and
  no gateway event may carry the `signature_key`, the `serverKey`, or a `qr_string` either
  (spec 6 applies to payloads we generate, not only to payloads we receive).
- **Acceptance**: events enumerated from `->audit()` call sites, added to the Event Map
  (Phase 16's 48 → P18's 52 → **65** — the voucher move shifted `voucher.claimed` from P18's
  four to this phase's thirteen), and each row commits or rolls back with its mutation. A
  `payment.refund_recorded` row must point at the transaction it reverses.
- **Docs**: `docs/base/features/audit-trail.md` Event Map; `docs/base/security/data-protection.md`
  for the `payment_logs.payload` redaction rule.

### Group E — Verification and closure

#### P19-E1 — Feature test suite
- **Task**: `tests/Feature/POS/{PosTransactionTest,PosShiftTest,ReceiptPrintTest,PaymentOrderTest,PaymentWebhookTest,PaymentWebhookRaceConditionTest,PaymentReconcileTest,FinancialDashboardTest,SaleTotalsTest}.php`
  + `tests/Feature/Reports/{FinancialPeriodTest,PosReportExportTest}.php` and the two settings
  files A7 names (`ReceiptSettingsUiRenderTest`, `ReceiptSettingsPersistenceTest`)
  + `tests/Feature/Voucher/VoucherRaceConditionTest.php` (moved from P18-E1: quota
  arithmetic, the unique usage-row backstop, a double claim refused) + `PaymentWebhookSignatureTest`
  (C2) and `PaymentWebhookRouteTest` (C4), plus the per-task files.
- **What `Http::fake` must replay** — D-15 is only worth the diff if the failure shapes are
  tested, not just the happy path: a connection error followed by **201**, then the
  `GET /v2/{order_id}/status` the retry owes (D-18); a **406** duplicate resolving to the
  stored row; a **4xx** that marks the row `failed` after exactly one attempt (asserting
  attempt counts is what proves `$when` was written); an open breaker answering the distinct
  error code without opening a socket; the C5 sweep against a `settlement`/`expire`/still
  `pending` trio.
- **The gates, each sabotage-verified**: remove the unique `(gateway, notification_hash)`
  index → the duplicate-delivery test goes red; remove the `WHERE status = 'pending'` guard →
  the race test goes red; feed a signature computed over the **request's** integer amount
  instead of the notification's `"145000.00"` string → the signature test goes red (D-16);
  deliver `pending` after `settlement` → nothing un-settles (D-17); short-pay a VA → held, not
  completed (O-8); a Rp 999.99 cart → the gateway payload is an integer and the ledger row and
  the printed receipt agree (spec 8, D-19); a 51-character `reference` is refused by the
  FormRequest before any call (D-18).
- **Financial**: tax and change arithmetic on exact `decimal` comparisons, including the
  rounding edge a 50 %-off + 11 % PPN cart produces; refund/chargeback notifications reduce
  the period's settled total (D-23).
- **The money/pass gate this phase adds** (D-24/D-25/D-31, each sabotage-verified): price one
  basket, flip `tax_mode`, reprint the **stored** row → the bytes are unchanged (the snapshot
  rule, not a re-derivation); an inclusive basket asserts `total == 111000` **and**
  `tax_total == 11000` **and** that no code path can add 11 % on top; `abs(rounding_adjust) < 1`
  with the printed lines summing to the printed grand total exactly, asserted for a 20-line
  cart; a taxable/non-taxable mix taxes only the taxable lines; the report's PPN column for a
  period is the same number as the dashboard's payable-PPN card for that period (one fixture
  population, both readers — the assertion that keeps the two screens from becoming two
  stories); `tax_ppn_applied = false` empties that column rather than printing Rp 0; and
  `PPh` appears in no ledger column a customer paid, only in the estimate column labelled as
  one.
- **Acceptance**: bars unmoved — global ≥ 85 %, Actions ≥ 95 %, Requests ≥ 95 %, Policies
  100 %, and the two new Actions of B2/C5 clear 95 % individually (D-10); wall clock stays
  recorded, not asserted (D-8 of Phase 15). No test in this phase needs a network, a printer,
  or a live Midtrans account — anything that would is § 7 evidence, not a suite member.
- **Tests**: are the tests.

#### P19-E2 — Full-suite run and tracker reconciliation
- **Task**: `vendor/bin/pint --test`, `vendor/bin/phpstan analyse --memory-limit=1G`,
  `php artisan test`, `route:list`, `schedule:list`, `composer audit --locked`,
  `npm ci && npm run build`; flip rows on evidence; update `progress.md`,
  `implementation-roadmap.md`, `qa-tracker.md`, `docs/base/` — including
  `docs/base/dependencies/dependency-matrix.md`, where `mike42/escpos-php` v5 appears with
  **`ext-intl` and `ext-zlib` named as install requirements** (ADR-037): a fresh box must
  fail at `composer install` with a message, not at the counter on the first receipt.
- **Acceptance**: counts quoted from the run; PHPStan ledger stays at 310 entries or its
  growth is named; `composer audit` includes the new escpos dependency **and no gateway
  package appears in `composer.lock`** (ADR-036 is only real if the lockfile agrees, and
  this is the check that catches someone having changed their mind mid-phase); nothing is
  DONE without an assertion someone opened and read; the deferred-evidence list (§ 7) is
  carried into `progress.md` § *Known Issues*, not dropped at closure.

---

## 6. Execution order and per-group gates

| Gate | Must be true before the next group opens |
|---|---|
| A | fixture render green for every new view (cashier, gateway card, dashboard, receipt settings, report), no DB and **no secret** in any view — the sweep covering the receipt card's logo path and the report page's signed download link, all three flags declared + seeded, Pint clean |
| B | migrate/rollback exit 0, Actions ≥ 95 %, oversell + shift reconciliation proven, the three completion triggers (cash, webhook, sweep) calling the **same** Action, `composer require` blocked until **ADR-037** exists, one `CalculateSaleTotalsAction` with no second arithmetic site (`grep` for the setting keys outside B8/A7 says so), the 32-column byte fixture green with no `cut()` call anywhere |
| C | every gateway call faked with asserted attempt counts (201-after-timeout, 406, 4xx-once), duplicate webhook settles once, out-of-order delivery changes nothing, bad signature answers 401 before any query, sweep proven idempotent, credentials invisible to `getAll()` and the API |
| D | every refusal measured at its status code (403 permission, 403 flag, 405 wrong verb), cash path proven alive with `payment_gateway` off **and** a paid-but-killed sale still settled by C5, Event Map at 65 rows |
| E | four gates exit 0, trackers reconciled, deferred evidence recorded — the printer's share of it being the hardware confirmations § 7 lists, and the escpos line in `dependency-matrix.md` |

## 7. What stays open after this phase (carried into `progress.md` § Known Issues)

- **Real hardware — the EP58M / RPP02 on this desk** (D-28). Proven in CI: the byte stream.
  Not provable here, and each needs one run against the unit before B4 is called DONE: the
  exact USB vendor/product id and whether the box presents `usblp` or needs a CUPS raw queue;
  that a 384-dot logo is dark enough to read on 58 mm (a light grey PNG is a blank strip, not a
  small logo); that the printed QRIS code **decodes** from a phone across a counter; whether
  this unit carries a drawer kick port at all; the worst-case print time inside a cashier
  request (the ~90 mm/s figure is the manual's, not measured); and how a 40-character product
  name actually wraps on real paper.
- **A live Midtrans sandbox round trip**: create credentials → set the key in A2 → charge →
  pay → receive the **real** notification → settle. This is where four of this phase's
  researched constants get proven or corrected: the signature algorithm and the exact
  `gross_amount` string it hashes (D-16, MD5-vs-SHA-512 is documented as a version split),
  `status_code`/`transaction_status` shapes (D-18/D-23), whether `expire_time` matches the
  `custom_expiry` we sent (D-19), and the delivery/retry behaviour of a non-2xx answer
  (D-17). `Http::fake` proves our shape, never theirs. Needs a public URL for the webhook
  (tunnel or staging) — the reason this cannot be a CI step.
- **Refund activation**: Midtrans keeps the refund API **disabled on POS transactions until
  the account is commercially activated** (D-23). Recorded as an operational prerequisite
  with a named owner action (contact sales / activate), not a task — and while it is
  disabled, a gateway-side reversal of collected money is a manual dashboard act.
- **Payout & MDR reconciliation**: the fee numbers A3 cannot compute (D-20) exist only in the
  Dashboard payout report (a 7-zip export) or BI-SNAP Reporting APIs with their own
  credentials. An import task is its own slice; it is the only way a fee card ever becomes
  truthful.
- **EDC**: `edc` is a keyed imprint, not a gateway call (D-14). Its truth lives in the
  acquirer's settlement file, so "cash drawer balanced, EDC line confirmed" is only provable
  against that file — outside this phase.
- **The MySQL two-session probe** for `lockForUpdate()` (D-5) — inert on the test
  connection, and it now covers the voucher claim, the points ledger and the webhook/sweep
  collision too; all four locks travel with their rows.
- Production credential rotation, and the PCI question nobody has answered: what this
  store is allowed to keep in `payment_logs.payload` (it carries customer phone/email —
  PII, not just money).

## 8. Open owner decisions

| # | Question | Options | Blocking? |
|---|---|---|---|
| O-1 | **D-4** — how does a product exist? | thin admin CRUD inside this phase / a separate master-data phase / seeder-only for the pilot | Blocks B1's commit. The table is forced either way; the *screen* is not |
| O-2 | **D-11** — cost price | add + maintain `products.cost` and ship the Profit card / ship revenue + fees + tax now, Profit later | Not blocking; decides whether A3's fourth card is real or a zero |
| O-3 | Which role may ring a sale | a new `cashier` role holding `pos.access` / `user` role extended / per-outlet role later | Blocks D1's seed matrix. A base project that invents a role is making a product decision |
| O-4 | Gateway vendor | **CLOSED 2026-11-08 — Midtrans, Core API v2 (VT-Direct), no gateway Composer package.** ADR-036 | Was blocking C1's driver shape; nothing in C is now |
| O-5 | `payment_logs` retention | reuse the daily `sweeps` pattern with `payment_log_retention_days` / keep forever | Not blocking; money ledgers usually keep, logs do not. Since D-17/D-22 the logs also carry customer phone/email, so the answer is a privacy call as much as a disk call |
| O-6 | **D-20** — the gateway fee card | (a) cut it from A3 (recommended, and what A3 is written to do); (b) hardcode an MDR rate table and print an unreconcilable figure; (c) build a payout/fee report import as its own slice | Not blocking — A3 ships (a). (c) is the only honest path to a real fee number |
| O-7 | **D-21** — on-screen QR | (a) printed QR on the receipt only, screen shows amount + countdown (recommended, zero dependency); (b) add a QR rendering package (npm or Composer) + its own ADR + a CSP-safe path | Not blocking — A1 ships (a). A counter whose QR lives only on paper forces the customer to walk back to the printer, which may be enough reason for (b); that is an owner's floor-plan question, not an engineering one |
| O-8 | **D-22** — a settled amount that does not equal the expected total (a partial VA payment) | (a) record it, refuse completion, hold for a `payments.manage` review (recommended, what C2 does); (b) accept the partial and mark the balance owed (a credit decision POS does not have); (c) auto-void | Not blocking — C2 ships (a). What the cashier *says* to a customer who has half-paid is a policy the repo cannot invent |
| O-9 | Split tender — the consequence D-14 opened | (a) one tender per sale (recommended for this phase, and what the schema enforces); (b) allow two tender lines on one basket, which needs a receipt that prints one total over two payments | Not blocking. (b) is a UI + receipt decision, and no Midtrans capability is missing for it — the two rows are already legal |
| O-10 | **D-29** — "uang keluar" beyond the drawer | (a) shift float in/out only, keyed and audited (recommended, what B3 + A3 ship); (b) a real expense ledger (vendor, category, approval, its own screens) as its own phase | Not blocking — A3 ships (a). (b) is a second module wearing this phase's menu entry, and the dashboard's money-out card is a lie without it |
| O-11 | **D-30** — export format | (a) CSV (recommended: streaming, no dependency, opens in Excel, what Phase 10 already does); (b) XLSX (needs `phpoffice/phpspreadsheet` + ADR + a memory story for a year of lines); (c) PDF (needs a PDF library, and a tax rekap an operator cannot re-check is worse than a CSV) | Not blocking — B9 ships (a). A `composer require` for (b) or (c) is its own changelog line + ADR |
| O-12 | **D-31** — is this install a **PKP** (Pengusaha Kena Pajak)? | (a) yes → `tax_ppn_applied` defaults on and `receipt_npwp` must be filled; (b) no → PPN **may not be charged at all**, the toggle defaults off, the report's PPN column stays empty, and charging it anyway is a liability the till cannot unwind; (c) leave both states reachable and let each outlet decide per installation | Blocking for A7's defaults and for anything said about filing. The rate is a number the code can read; **the right to collect it is a legal status this repo cannot infer** |
| O-13 | **D-26** — does the service charge enter the loyalty points base? | (a) points on the goods subtotal only (recommended — points on a service fee rewards the fee); (b) points on the full paid total (what a customer who reads "earn on what you pay" will expect) | Not blocking for B8's arithmetic; it decides one line in `EarnMemberPointsAction`'s call from B2, and Phase 18's rules do not answer it |
