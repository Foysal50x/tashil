# Tahsil — Documentation

Tahsil is a Laravel package for **subscription and feature management**. It owns plan definitions, subscription state, feature gating, usage counters, trial lifecycle, scheduled state transitions, and invoice issuance. It does **not** charge cards — money movement (payment capture, dunning retries, refunds, gateway reconciliation) is delegated to a third-party integration in the host application.

## Document index

| # | Document | Read it when… |
|---|---|---|
| 01 | [DB Schema](01-DB-Schema.md) | You need the table shapes, indexes, and the ER diagram. |
| 02 | [Feature System](02-Feature-System.md) | You're defining features, attaching them to packages, or tracking usage. |
| 03 | [Trial System](03-Trial-System.md) | You're working on trial start, conversion, expiry, or grace. |
| 04 | [Scheduler Jobs](04-Scheduler-Jobs.md) | You're running, overriding, or extending the scheduled commands. |
| 05 | [Reporting Data Model](05-Reporting-Data-Model.md) | You need analytics, audit, or point-in-time reporting. |
| 06 | [Developer Guide](06-Developer-Guide.md) | You're extending tahsil — new events, custom features, custom storage. |

## Scope at a glance

In scope:

- Plans (packages) with billing model, trial config, feature definitions.
- Polymorphic subscribers (`User`, `Team`, etc. via `HasSubscriptions`).
- Subscription lifecycle: create → active / on-trial → cancel (grace or immediate) → resume → switch → pause → expire.
- Trial lifecycle: start → ending warning → convert | expire.
- Feature gating (boolean, limit, consumable, enum) with atomic, race-safe usage tracking.
- Scheduled quota resets anchored to previous period_end (no cron drift).
- Scheduled package changes (`scheduleDowngrade` applied at period end).
- Immutable per-subscription event log with monotonic `sequence_num` and idempotency keys.
- Immutable per-subscription feature snapshots (history preserved across plan changes).
- Renewal invoice issuance and dispatch of `InvoiceIssued` / `InvoicePaid` events.
- Analytics: MRR, churn, trial conversion, per-package breakdown (existing `tpetry/query-expressions` queries).

Out of scope (host owns these):

- Card capture, payouts, refund execution, payment gateway sync.
- Dunning retry policy and webhook reconciliation.
- Hash-chained financial ledger.
- Coupons and discount engine.
- MRR/ARR revenue-waterfall reporting beyond what's already in `AnalyticsService`.
