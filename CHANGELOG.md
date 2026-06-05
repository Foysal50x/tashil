# Changelog

All notable changes to `foysal50x/tashil` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **Transaction ledger API** — `BillingService::recordPayment()`,
  `recordFailedPayment()`, and `recordRefund()` (via `Tashil::billing()`) record the
  payments/refunds the host's gateway executes, each writing the `Transaction`
  audit row **and** reflecting the invoice state in one DB transaction. Tashil
  still never moves money — these only *record* what the host reports.
  - `recordPayment()` writes a `success` transaction and marks the invoice paid
    (routing through `InvoiceObserver` → activate / advancePeriod / reactivate).
  - `recordFailedPayment()` writes a `failed` transaction and leaves the invoice
    `Pending` for dunning.
  - `recordRefund()` accumulates `refunded_amount` (partials supported); a full
    refund flips the transaction to `Refunded` and the invoice to `Refunded`.
  - `recordPayment` / `recordFailedPayment` are idempotent on
    `UNIQUE(gateway, transaction_id)` — a replayed at-least-once webhook resolves
    to the existing row and never double-settles.
- **`TransactionRepositoryInterface` + `EloquentTransactionRepository`** — the
  transaction ledger now sits behind an overridable repository like every other
  persistence concern.
- **Events** `PaymentRecorded`, `PaymentFailed`, `PaymentRefunded` — carry the
  transaction + invoice, dispatched after commit.
- **`Invoice::markAsRefunded()` / `Invoice::isRefunded()`** and a
  `gateway_response` array cast on `Transaction`.
- **Invoice/transaction read API** on `BillingService` (`Tashil::billing()`):
  `latestInvoice($sub, ?InvoiceKind)`, `pendingInvoice($sub)`,
  `overdueInvoice($sub)`, and `successfulTransaction($invoice)` — so host code
  reads invoices through the (overridable) repository instead of querying the
  `Invoice` model directly. Backed by new `InvoiceRepositoryInterface` methods
  (`latestForSubscription`, `pendingForSubscription`, `overdueForSubscription`)
  and `TransactionRepositoryInterface::latestSuccessfulForInvoice`; these read
  paths are uncached (per-subscription invoices change on every payment/dunning
  step).

### Changed

- Cookbook examples (`PaymentWebhookController`, `ChargeRenewalInvoice`,
  `DunningListeners`) now use `recordPayment()` / `recordFailedPayment()` instead
  of hand-written `Transaction::create()` + `markAsPaid()`; added a
  `RefundController` example. `CheckoutController`, `TrialController`, and
  `SuspensionController` now read invoices via the billing API instead of direct
  `Invoice` queries. Billing docs document the ledger + refund flow and the read
  API.

### Fixed

- **`TransactionIdGenerator` uniqueness is now scoped to the gateway.** It takes
  the row's gateway via a new constructor argument (passed by
  `TransactionObserver`) and checks the composite `(gateway, transaction_id)` —
  matching the DB constraint — instead of the bare `transaction_id`. The old
  global check wrongly rejected (and regenerated) an id that already existed
  under a *different* gateway; the same id can legitimately exist per gateway.
  Custom transaction generators may declare a `string $gateway` constructor
  parameter to receive it.

## [1.0.0-beta] - 2026-06-05

First public beta. Subscription + feature management for Laravel with an
immutable event store, atomic usage tracking, and a host-driven billing
lifecycle. Tashil owns plan catalog, subscription state, gating, counters,
trial lifecycle, invoice issuance, and the activate → renew → dunning →
reactivate state machine — but never moves money.

### Added

- **Plan catalog** — fluent `PackageBuilder` / `FeatureBuilder` for packages and
  features. Five feature types: Boolean, Limit, Consumable, Enum, Metered.
- **Subscription lifecycle** — subscribe, cancel (grace + immediate), resume,
  pause/unpause (banks remaining time), expire, `switchPlan` (cancel + new),
  `changePlan` (in-place, prorated), and `scheduleDowngrade` (deferred to period end).
- **Activation gating** — priced `requires_payment` plans subscribe `Pending`
  with no access; an `initial` invoice is issued and `activate()` runs on payment,
  anchoring the period to `paid_at`. Free plans go straight to `Active`.
- **Trial system** — first-class trials with strict `isOnTrial()`, host-triggered
  `convertTrial()` that anchors the first paid period, and scheduled trial
  warning / expiry jobs.
- **Billing lifecycle** — `InvoiceObserver` routes paid invoices by kind +
  status (initial → activate, renewal → advance period, lapsed → reactivate).
  Proration on in-place plan changes (cross-currency proration throws).
- **Dunning** — bounded state machine (`Active → PastDue → Suspended → Expired`)
  via `tashil:process-dunning`, with grace caps and recovery on payment. The host
  performs the actual retry charge.
- **Atomic usage tracking** — limit increments via conditional UPDATE (no
  over-limit races), absolute reporting via `reportStorage`, `UsageLimitWarning`
  once per period on the 80% crossing, and period-anchored quota resets.
- **Metered billing** — per-unit charges delegated to a host `MeteredBilling`
  implementation (self-impl on the model or container-bound). Charge-before-write
  ordering, caller-supplied idempotency keys, and orphan-charge logging.
- **Immutable event log** — `tashil_subscription_events` with strictly monotonic
  per-subscription `sequence_num` (assigned under `SELECT … FOR UPDATE`),
  idempotency keys, and append-only / immutable enforcement. Paginated read API
  via `Tashil::events()`.
- **Analytics** — `Tashil::analytics()`: MRR, churn rate + trend, trial
  conversion, revenue by package/period, dashboard summary (cross-database via
  `tpetry/laravel-query-expressions`, no raw SQL).
- **Subscribable contract + `HasSubscriptions` trait** — polymorphic subscriber
  surface with an overridable `resolveSubscription()` for multi-sub / tenant hosts.
- **Route middleware + Blade directives** — `subscribed`, `plan:{slug}`,
  `feature:{slug}` and `@subscribed` / `@plan` / `@feature` / `@onTrial`, sharing
  one overridable subscribable resolver.
- **Six scheduled commands** — idempotent `tashil:*` jobs auto-registered with
  `->onOneServer()`, version-agnostic across Laravel 10–13.
- **Caching architecture** — repository decorators for cold catalog + analytics
  on an isolated `tashil` Redis store; hot mutating tables bypass cache.
- **Guaranteed-unique id generation** — `InvoiceNumberGenerator` /
  `TransactionIdGenerator` implementing `ShouldBeUnique`, backed by DB unique
  constraints for concurrency safety.

### Requirements

- PHP 8.2 – 8.5
- Laravel 10.x, 11.x, 12.x, or 13.x
- Redis (optional — only when the caching layer is enabled)

### Notes

- Beta release: the public API is feature-complete and covered by 363 tests
  (1067 assertions) across SQLite, MySQL 8, and PostgreSQL 16, but is subject to
  refinement before `1.0.0` stable.
- Tashil does not own payment capture, dunning retry charges, refunds, gateway
  sync, or wallet balances — these are delegated to the host application.

[Unreleased]: https://github.com/Foysal50x/tashil/compare/v1.0.0-beta...HEAD
[1.0.0-beta]: https://github.com/Foysal50x/tashil/releases/tag/v1.0.0-beta
