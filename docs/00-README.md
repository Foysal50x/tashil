# Tahsil — Documentation

Tahsil is a Laravel package for **subscription and feature management**. It owns plan definitions, subscription state, feature gating, usage counters, trial lifecycle, scheduled state transitions, invoice issuance, the activation/dunning/reactivation state machine, and proration on plan changes. It does **not** charge cards — money movement (payment capture, the actual retry charge, refund execution, gateway reconciliation) is delegated to a third-party integration in the host application. Tahsil owns the *state machine, the invoices, and the schedule*; the host moves the money.

## Document index

| # | Document | Read it when… |
|---|---|---|
| 01 | [DB Schema](01-DB-Schema.md) | You need the table shapes, indexes, and the ER diagram. |
| 02 | [Feature System](02-Feature-System.md) | You're defining features, attaching them to packages, or tracking usage. |
| 03 | [Trial System](03-Trial-System.md) | You're working on trial start, conversion, expiry, or grace. |
| 04 | [Scheduler Jobs](04-Scheduler-Jobs.md) | You're running, overriding, or extending the scheduled commands. |
| 05 | [Reporting Data Model](05-Reporting-Data-Model.md) | You need analytics, audit, or point-in-time reporting. |
| 06 | [Developer Guide](06-Developer-Guide.md) | You're extending tahsil — new events, custom features, custom storage. |
| 09 | [Billing Lifecycle](09-Billing-Lifecycle.md) | You're working on activation, renewal, dunning, reactivation, or proration. |

## Scope at a glance

In scope:

- Plans (packages) with billing model, trial config, feature definitions.
- Polymorphic subscribers — any Eloquent model that `implements Subscribable` (`User`, `Team`, `Organization`, tenant models) via the `HasSubscriptions` trait.
- Subscription lifecycle: create → pending → active / on-trial → past-due → suspended → cancel (grace or immediate) → resume → reactivate → switch → pause → expire.
- Activate-on-payment model: priced plans subscribe as `pending` and gain access only when the first invoice is paid (see [09-Billing-Lifecycle.md](09-Billing-Lifecycle.md)).
- Dunning: failed renewals escalate past-due → suspended → expired on a configurable retry schedule (`tashil:process-dunning`); reactivation on payment.
- Proration: in-place `changePlan()` bills the prorated delta on an upgrade and carries usage forward; downgrades defer to period end.
- Trial lifecycle: start → ending warning → convert (anchors first paid period, issues first invoice) | expire.
- Feature gating (boolean, limit, consumable, enum, metered) with atomic, race-safe usage tracking.
- Metered features: per-unit charges against a host-implemented `MeteredBilling`. Tahsil owns the snapshot + counter + event log; the host owns the balance.
- Route middleware (`subscribed`, `plan:{slug}`, `feature:{slug}`) and a pluggable subscribable resolver (`Tashil::resolveSubscribableUsing`) for multi-tenant hosts.
- Scheduled quota resets anchored to previous period_end (no cron drift).
- Scheduled package changes (`scheduleDowngrade` applied at period end).
- Immutable per-subscription event log with monotonic `sequence_num` and idempotency keys.
- Immutable per-subscription feature snapshots (history preserved across plan changes).
- Renewal invoice issuance and dispatch of `InvoiceIssued` / `InvoicePaid` events.
- Analytics: MRR, churn, trial conversion, per-package breakdown (existing `tpetry/query-expressions` queries).

Out of scope (host owns these):

- Card capture, payouts, refund execution, payment gateway sync.
- The actual retry *charge* during dunning and webhook reconciliation — Tahsil owns the dunning *state machine and schedule* and fires the events; the host performs the charge.
- Hash-chained financial ledger.
- Coupons and discount engine.
- Cross-currency proration (rejected — cancel + resubscribe instead) and FX normalization.
- MRR/ARR revenue-waterfall reporting beyond what's already in `AnalyticsService`.
