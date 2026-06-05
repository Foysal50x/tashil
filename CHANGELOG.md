# Changelog

All notable changes to `foysal50x/tashil` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
