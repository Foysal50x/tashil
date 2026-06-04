# CLAUDE.md — Tashil

AI working guide for `foysal50x/tashil`. Read before touching code.

## What this package is

Laravel subscription + feature management. **Owns** plan catalog, subscription state, feature gating, atomic usage counters, trial lifecycle, scheduled state transitions, invoice issuance, the **activate-on-payment → renewal → dunning → reactivation** state machine, **proration on in-place plan changes**, immutable event log, and route middleware for gating. **Does NOT own** payment capture, the actual retry *charge* during dunning, refund execution, gateway sync, or the wallet/balance that funds Metered features — host app handles money movement and binds `MeteredBilling` for metered billing. Read [docs/09-Billing-Lifecycle.md](docs/09-Billing-Lifecycle.md) before touching activation / renewal / dunning / proration code.

**Billing model:** a priced plan (`price > 0`, `requires_payment = true`) subscribes as `Pending` with NO access; an `initial` invoice is issued, and `activate()` runs when it's paid (anchoring the period to `paid_at`). Free / `requires_payment = false` plans subscribe `Active` immediately. Trials subscribe `OnTrial` (access granted) and bill at `convertTrial()`. Gating is decided by the **package's own `requires_payment` flag** — `tashil.billing.activate_on_payment` (default `true`) only seeds that flag at creation when the caller doesn't set it; flipping the config later affects only new packages. Create packages with `requires_payment = false` for the legacy "access first, bill on renewal" model.

Composer: `foysal50x/tashil` · Namespace: `Foysal50x\Tashil` · PHP `^8.2` · Laravel `^10|^11|^12|^13`.

## Authoritative docs

Source of truth for design questions. Read before changing related code.

| Topic | File |
|---|---|
| Tables + ER + indexes | [docs/01-DB-Schema.md](docs/01-DB-Schema.md) |
| Feature types (incl. Metered), snapshot, counter, reset | [docs/02-Feature-System.md](docs/02-Feature-System.md) |
| Trial lifecycle, isOnTrial semantics | [docs/03-Trial-System.md](docs/03-Trial-System.md) |
| Six scheduled commands + idempotency | [docs/04-Scheduler-Jobs.md](docs/04-Scheduler-Jobs.md) |
| Event log, point-in-time replay, analytics | [docs/05-Reporting-Data-Model.md](docs/05-Reporting-Data-Model.md) |
| Layout, conventions, Subscribable, middleware, MeteredBilling | [docs/06-Developer-Guide.md](docs/06-Developer-Guide.md) |

If you change behavior, update the matching doc in the same change.

## Layout

```
src/
  Builders/         PackageBuilder, FeatureBuilder — fluent catalog construction
  Console/          Six scheduled commands (tashil:*)
  Contracts/        Subscribable, MeteredBilling, repository interfaces
  Enums/            SubscriptionStatus, FeatureType (Boolean/Limit/Consumable/Enum/Metered),
                    ResetPeriod, UsageOperation, InvoiceStatus, TransactionStatus, Period
  Events/           Subscription / Trial / Invoice / Usage / Metered events
  Exceptions/       MeteredBillingNotConfiguredException
  Facades/          Tashil
  Http/Middleware/  EnsureSubscribed, EnsurePlan, EnsureFeature
  Managers/         DatabaseManager, CacheManager
  Models/           BaseModel + Eloquent models
  Observers/        InvoiceObserver, TransactionObserver
  Providers/        TashilServiceProvider (binds + middleware + Blade + schedule)
  Repositories/     EloquentX + Cache decorators
  Services/         SubscriptionService, UsageService, BillingService,
                    AnalyticsService, EventStore, Resetter, Generators/,
                    Providers/NullMeteredBilling
  Support/          DateFmt, helpers
  Traits/           HasSubscriptions
  Tashil.php        Facade target — sub-accessors + resolveSubscribable APIs
database/           Single migration + factories
tests/Feature       Integration tests (real DB, time travel)
tests/Unit          Builder / Model / Enum tests
docs/               Authoritative design docs (see table above)
examples/           Cookbook usage
```

## Layering — respect it

```
HasSubscriptions trait (implements Subscribable)
    → Tashil facade → SubscriptionService / UsageService / BillingService / AnalyticsService / EventStore
        → Repository interfaces (Contracts/)
            → Eloquent or Cache-decorated impls
                → Eloquent models → DB
        → MeteredBilling (host-implemented) — called by UsageService for Metered features
        → Subscribable resolver — called by middleware via Tashil::resolveSubscribable
```

Rules:
- Hot, mutating models (`SubscriptionEvent`, `SubscriptionFeature`, `FeatureUsage`) bypass cache. Don't add cache decorators here.
- Cold catalog (`Package`, `Feature`) + analytics flow through cache decorators.
- New persistence concerns go behind an interface in `Contracts/`. Bind in `TashilServiceProvider::register()` so host `boot()` can override.
- Subscriber type-hints across the codebase are `Subscribable`, never Eloquent's `Model`. If you find a stray `Model $subscriber`, fix it.

## Hard invariants — DO NOT break

These are enforced in code AND tested. Breaking them = bug.

1. **`SubscriptionEvent` is fully immutable.** `booted()` throws on any update or delete. Append via `EventStore::append`, never `->update()` / `->delete()`.
2. **`SubscriptionFeature` is append-only.** Only `superseded_at` (and `updated_at`) may change. Plan switch = stamp old `superseded_at = now()`, insert new row. Never update `value` / `feature_slug` / `feature_type`.
3. **`sequence_num` is strictly monotonic per subscription.** Assigned under `SELECT … FOR UPDATE` lock in `EventStore::append`. Never assign manually.
4. **`isOnTrial()` is strict.** `status === OnTrial` AND `trial_ends_at !== null` AND `trial_ends_at` in future. Regression test in `tests/Unit/Models/SubscriptionStatusHelpersTest.php` — don't loosen.
5. **Limit increment is atomic via conditional UPDATE.** Don't replace with read-modify-write — it allows over-limit races. The SQL is:
   ```sql
   UPDATE tashil_feature_usages SET usage = usage + :amount
   WHERE id = :id AND (limit_value IS NULL OR usage + :amount <= limit_value)
   ```
   `affected_rows = 0` ⇒ rejected, counter untouched, return `false`.
6. **Reset cadence anchors to previous `period_end`, NOT `now()`.** A late cron must not drift the schedule. Same for `usage.reset` event idempotency key `"usage-reset:{usage_id}:YYYY-MM-DD-HH"`.
7. **Events fire after DB commit** via `Service::dispatchAfterCommit()` when `tashil.events.async = true` (default). Don't dispatch inline inside a transaction.
8. **Renewal does NOT advance `current_period_end`; only a paid invoice does, routed by kind.** `InvoiceObserver::updated` (status → Paid) routes by `invoice.kind` + subscription status: `initial`+`Pending` → `activate()`; `renewal`+`Active`/`OnTrial` → `advancePeriod()`; lapsed (`PastDue`/`Suspended`/`Expired`) → `reactivate()`; `proration` or `initial`-on-active → nothing. `advancePeriod()` is guarded to `Active`/`OnTrial` only (M1) — a stray paid invoice can never shift a cancelled/expired/paused period. Tashil stays out of payment execution.
9. **Grace cancel keeps access.** `PendingCancellation` remains in `Subscription::valid()` scope; `$user->subscribed()` returns true until `cancellation_effective_at`.
10. **Trial conversion is host-triggered and anchors the first paid period.** Tashil never auto-converts on payment — the host calls `convertTrial()`. `convertTrial()` sets `Active` + `trial_converted_at`, re-anchors `current_period_*` to the conversion moment (no free remainder of the trial-subscribe period), and issues the first `initial` invoice for priced plans. The renewal cron never bills an `OnTrial` sub (`dueForRenewal` is `Active`-only).
11. **`UsageLimitWarning` fires once per period.** On the first crossing of 80%. Don't fire on every increment or every report. Does NOT fire for Metered features (no `limit_value`).
12. **Idempotency keys are mandatory for retryable operations.** Scheduled jobs (`trial-ending:{sub}:{date}`, `usage-reset:{usage_id}:YYYY-MM-DD-HH`) use them. Host-custom events via `EventStore::append` should too. Metered charges pass `'metered:{sub_id}:{feature_id}:{uuid}'` via the provider context — the provider MUST honor it.
13. **Soft-deletes on `packages` / `features` / `subscriptions` / `invoices` only.** Snapshot / counter / logs / events / transactions intentionally have none — they're append-only audit or cascade-managed.
14. **Metered: charge before write, never after.** `UsageService::consumeMetered` calls `MeteredBilling::charge` first; only on `true` does it open the transaction that writes counter + log + event + `MeteredCharged`. On `false`: nothing is written, `MeteredChargeRejected` dispatches, `useFeature()` returns `false`. Do not reverse the order — a write-then-charge model can advance the counter while the balance refuses.
15. **Metered counters have `limit_value = NULL`.** `EloquentSubscriptionRepository::syncFeatures` enforces this — the pivot `value` for Metered holds the unit_price, not a cap. The gate is `MeteredBilling::charge`, not `atomicIncrement`.
16. **`reportStorage()` refuses Metered features.** Delta-charge model is incompatible with absolute-set semantics. Returns `false` early. Don't soften.
17. **Subscriber type-hints are `Subscribable`.** `SubscriptionService::subscribe`, `SubscriptionRepositoryInterface`, `CacheSubscriptionRepository`, `MeteredBilling` all type-hint `Subscribable`. Host models that use `HasSubscriptions` MUST `implements Subscribable`. The trait provides default impls; this is a hard requirement, not a suggestion.
18. **`HasSubscriptions::loadSubscription` goes through `resolveSubscription`.** Every feature/lifecycle helper resolves "the active subscription" via the overridable `resolveSubscription()` method, then memoizes. Don't bypass the resolver for direct repo calls — multi-sub hosts depend on the override.
19. **`MeteredBilling` resolution is per-subscriber, not global.** `UsageService::resolveMeteredBilling(Subscribable $s)` returns `$s` itself if it `instanceof MeteredBilling`, else `app(MeteredBilling::class)`. Two host patterns coexist with no config flag: self-implementing models (`User implements Subscribable, MeteredBilling`) and standalone classes bound in the container. Don't force one or the other — both must keep working. Hosts can mix per subscriber type.
20. **Amount validation at every entry point.** `increment` / `useFeature` / `check` reject `$amount <= 0`; `reportStorage` rejects `$amount < 0` (zero is a valid absolute report). Without these guards, negative amounts pass the conditional UPDATE (`usage + (-10) <= limit_value`) and silently *decrease* counters or send negative charges that providers treat as credits. The guards return `false` early — never throw, never decrement.
21. **`NullMeteredBilling` is asymmetric: read paths safe-deny, `charge` throws.** `getBalance` returns `0.0` and `hasSufficientBalance` returns `false` so `UsageService::check`, `@feature(...)`, and the EnsureFeature middleware degrade to "deny" (not 500) when no real provider is bound. `charge` still throws `MeteredBillingNotConfiguredException` because silently dropping a money-moving call is worse than crashing. Do not change either direction without changing the other's contract too.
22. **Caller-supplied idempotency key flows through `useFeature($slug, $amount, ?string $key = null)`.** When the caller passes a stable token (request ID, job UUID, domain op ID), it's forwarded verbatim in `$context['idempotency_key']` to the provider. When null, Tashil generates a fresh UUID per call (format `metered:{sub}:{feature}:{uuid}`) — useful only for provider-internal retries, useless for app-level retry dedup. Don't drop the parameter; hosts with retry-prone call paths need it.
23. **Orphan-charge path: log critical, re-throw.** If the inner DB transaction in `consumeMetered` throws after the provider already charged, `Log::critical` fires with `{idempotency_key, subscription_id, feature_slug, units, amount, currency, exception}` and the exception is re-thrown. The caller MUST handle the exception (it surfaces from `useFeature` which is documented as `: bool`, but throws on this specific path). Do not catch-and-return-false here — that hides the broken state from operators.
24. **Activation gating is the default; `Pending` has no access.** `subscribe()` on a priced `requires_payment` plan creates `Pending` (excluded from `isValid()`/`scopeValid()`) and issues an `initial` invoice. Access begins at `activate()` (first payment), which anchors `current_period_*` to `invoice.paid_at` and re-anchors counters via `usageRepo->reanchorPeriods`. Free / `requires_payment = false` plans go straight to `Active`. The internal `provision(…, gatePayment:)` carries the branch; `switchPlan` calls it with `gatePayment: false` so a plan switch never loses access or double-bills. **Gating is decided by the stored `package.requires_payment`, never by config at runtime** — `requiresPayment()` reads the package only. `Package::booted()` is the single chokepoint that seeds `requires_payment` from `tashil.billing.activate_on_payment` at creation *when the caller didn't set it* — covering every creation path (builder, factory, raw `Package::create()`, repository). The `PackageBuilder` holds `?bool $requiresPayment = null` and omits the key from `toArray()` unless `requiresPayment()` was called, so it *defers* to that seed; the factory likewise sets no value. The config never overrides an explicitly-set flag, and changing it later affects only new packages. Don't reintroduce a config check in `requiresPayment()`, and don't hardcode a default in the builder/factory (it would bypass the seed and silently ignore `activate_on_payment` on that path).
25. **Dunning is a bounded state machine Tashil owns; the host does the charge.** `tashil:process-dunning` escalates unpaid overdue `renewal` invoices: `Active → PastDue → Suspended → Expired` per `tashil.dunning.*`. `markPastDue`/`suspend` are the transitions; `SubscriptionPastDue`/`InvoiceOverdue`/`SubscriptionSuspended` are the signals. `PastDue` keeps access iff `dunning.keep_access_while_past_due` (default true); `Suspended` never has access. Paying recovers via `reactivate()` (clears `dunning_attempts`/`suspended_at`). `extend_grace` is capped by `renewal.max_grace_extensions`.
26. **`changePlan()` is in-place + prorated; `switchPlan()` is cancel+new.** `changePlan()` keeps the SAME subscription row and usage — upgrades apply now and bill the prorated delta on a `proration` invoice (≥ `billing.min_proration_amount`), downgrades defer via `scheduleDowngrade`. `resyncFeatures(carryUsage: true)` supersedes the current snapshots and re-writes from the new plan, carrying the usage value (updating cap + reset cadence). Cross-currency proration throws. Don't reset usage on a same-row plan change.
27. **`reactivate()` only ever recovers a lapsed sub.** It is a no-op unless status is `PastDue`/`Suspended`/`Expired`. It restores `Active`, clears dunning state, and keeps a still-future period or starts a fresh one. Paying a stray invoice on a cancelled/active sub does NOT reactivate.
28. **Pause banks remaining time.** `pause()` stores `metadata.paused_remaining_seconds` (seconds to `ends_at`); `unpause()` adds it back from the resume moment so paused time is never silently forfeited. Lifetime / open-ended subs bank nothing.
29. **Every reset writes a log + `usage.reset` event — including the lazy inline one.** `consumeCounter`'s inline reset (for an elapsed period when the cron is late) goes through `performReset` inside the consume transaction, so it is captured for log-replay. Don't call `usageRepo->resetUsage` directly from a service path — it skips the audit row and the event.
30. **Conversion is measured by `trial_converted_at`, not current status.** `trialConversionRate` / dashboard / package analytics count `trial_converted_at IS NOT NULL`, so a trial that converted then later cancelled/expired still counts as converted.

## Conventions

- **Strict typing on all signatures.** Constructor property promotion preferred. Use explicit return types.
- **Every mutating service method wraps work in `DB::transaction()`** via injected `DatabaseManager`. Closure must be idempotent on retry.
- **Float for usage.** `tashil_feature_usages.usage` is `DECIMAL(20,4)` cast to `float`. Pass float to `increment()` / `reportStorage()`.
- **Table names + connection from config.** `BaseModel` resolves via `class_basename → snake_plural → config('tashil.database.tables.<key>')` and prefixes with `config('tashil.database.prefix')`. Renaming a table = config change, not code.
- **`tashil:` command prefix, `tashil_` table prefix, `tashil.*` config keys** — even though package is namespaced `Foysal50x\Tashil`. Keep consistent; don't introduce `tahsil_` variants.
- **No banner comments** (`// ── Section ──`). They look AI-generated and rot. Use proper docblocks for the WHY when non-obvious; otherwise let well-named methods speak.

## Code style

Laravel Pint preset with overrides (see [pint.json](pint.json)):
- `=>` aligned single-space minimal
- One-space concat (`'a' . 'b'`)
- Alpha-sorted imports
- No unused imports
- Trailing commas in multiline arrays / args / params

Run before commit: `composer lint` (apply) / `composer lint:test` (check).

## Testing

Pest with `orchestra/testbench`. Real DB + real time travel (`$this->travel(N)->days()`). SQLite, MySQL 8, PostgreSQL 16 in CI. 355 tests / 1031 assertions.

```bash
composer test           # full suite
composer test:unit
composer test:feature
```

Reference fixtures: `tests/Fixtures/User.php` (implements Subscribable), `tests/Fixtures/create_users_table.php`, `tests/Fixtures/FakeMeteredBilling.php`. Copy into host test scaffold — not exported.

Test map for common changes:

| Touching… | Test file |
|---|---|
| Subscribe / events / snapshot / counter | `tests/Feature/SubscriptionFlowTest.php` |
| Activation (pending → active on payment, free/offline, legacy) | `tests/Feature/ActivationFlowTest.php` |
| Trial billing (no mid-trial bill, convert anchors + invoices) | `tests/Feature/TrialBillingTest.php` |
| Dunning (past_due → suspended → expired, recovery, grace cap) | `tests/Feature/DunningTest.php` |
| Reactivation (pay-on-lapse, M1 guard) | `tests/Feature/ReactivationTest.php` |
| Proration (changePlan upgrade/downgrade, carry-usage, currency) | `tests/Feature/ProrationTest.php` |
| Pause banking / resume auto_renew / inline-reset audit / dup-guard | `tests/Feature/LifecycleCleanupsTest.php` |
| EventStore (monotonic, idempotent, immutable) | `tests/Feature/EventStoreTest.php` |
| Usage increment / threshold / reset / report | `tests/Feature/UsageTrackingTest.php` |
| Metered features (charge order, idempotency, currency, rejection, self-impl vs container, caller-supplied key, orphan-charge log) | `tests/Feature/MeteredFeatureTest.php` |
| Amount validation (negative / zero rejection across increment / check / reportStorage / useFeature) | `tests/Feature/AmountValidationTest.php` |
| NullMeteredBilling safe defaults vs charge throw | `tests/Feature/MeteredCoverageTest.php` |
| Subscribable contract + resolveSubscription override | `tests/Feature/SubscribableContractTest.php` |
| Middleware (subscribed / plan / feature) | `tests/Feature/MiddlewareTest.php` |
| Blade directives (@subscribed / @plan / @feature / @onTrial) | `tests/Feature/BladeDirectivesTest.php` |
| Scheduler commands | `tests/Feature/ProcessSubscriptionsTest.php` |
| `isOnTrial` + status helpers | `tests/Unit/Models/SubscriptionStatusHelpersTest.php` |
| Analytics (MRR, churn, dashboard) | `tests/Feature/AnalyticsServiceTest.php`, `DashboardSummaryTest.php`, `PackageAnalyticsTest.php` |
| Trait surface | `tests/Feature/HasSubscriptionsTraitTest.php`, `HasSubscriptionsAdditionalTest.php` |

Adding a feature ⇒ add a Feature test. Fixing a bug ⇒ add a regression test that fails before the fix.

## Where to make common changes

| Change | Touch |
|---|---|
| New subscription state transition | `SubscriptionService` method + `EventStore::append` + event class + state machine doc |
| Activation / renewal / dunning / proration | `SubscriptionService` (`activate`/`reactivate`/`markPastDue`/`suspend`/`changePlan`), `InvoiceObserver` routing, `ProcessDunningCommand` + [docs/09-Billing-Lifecycle.md](docs/09-Billing-Lifecycle.md) |
| New feature type | `FeatureType` enum + `FeatureBuilder::xxx()` helper + `Feature::isXxx()` + `UsageService::check`/`increment` + `EloquentSubscriptionRepository::syncFeatures` (limit_value rules) + doc 02 |
| New scheduled job | `src/Console/` command + wire in `TashilServiceProvider::registerSchedule()` + doc 04 |
| Custom invoice / transaction id | Implement `generate(): string` (extend `TokenizedIdGenerator` for token format reuse) + bind in `config/tashil.php` `invoice.generator` / `transaction.generator` |
| New repository impl | Implement contract in `src/Contracts/` + bind in host provider (overrides Tashil's `register()`) |
| Add cache to a read path | Add decorator next to `Repositories/Cache*Repository.php`; invalidate on relevant writes. Never cache hot mutating tables |
| New middleware alias | Add class in `src/Http/Middleware/`, register in `TashilServiceProvider::registerMiddleware()`, add config key in `tashil.middleware.aliases` |

## Host integration contract

```
Tashil issues Invoice (status=pending) → fires InvoiceIssued
Host listener charges via gateway → on success calls $invoice->markAsPaid()
Tashil InvoiceObserver (status → Paid):
  → SubscriptionService::advancePeriod($sub)
  → EventStore::append('subscription.renewed')
  → dispatch SubscriptionRenewed + InvoicePaid
```

For dunning / webhook reconciliation / refunds, host wires equivalent listeners. Tashil ends at issuing the bill and reflecting the host's `markAsPaid` decision.

Transactions: pass gateway-supplied id through (`ch_…`, `txn_…`). `UNIQUE(gateway, transaction_id)` on `tashil_transactions` makes duplicate webhook deliveries safe — catch `UniqueConstraintViolationException` as "already recorded". For cash / manual entries, leave `transaction_id` empty — `TransactionObserver::creating` stamps `TXN-…` from `tashil.transaction.generator`.

### Metered billing contract

```
$user->useFeature('ai-tokens', $units)
  → UsageService::consumeMetered
    → resolve unit_price from current SubscriptionFeature snapshot
    → resolve currency from subscription.package.currency
    → resolveMeteredBilling($subscriber):
        if $subscriber instanceof MeteredBilling → use $subscriber (Pattern A: self-impl)
        else                                     → app(MeteredBilling::class)  (Pattern B: container)
    → MeteredBilling::charge($subscriber, $currency, $units × $unit_price, $context)
        ↳ host: debit wallet/balance, dedupe on $context['idempotency_key']
        ↳ true  → counter ++, usage_log + 'usage.metered_charged' event + MeteredCharged
        ↳ false → nothing written, MeteredChargeRejected, useFeature returns false
```

Two implementation paths:

- **Pattern A (self-impl):** host adds `implements MeteredBilling` directly on the Subscribable model. No container binding required.
- **Pattern B (container):** host binds a standalone class. `$this->app->bind(MeteredBilling::class, WalletMeteredBilling::class)`.

Default = `NullMeteredBilling` (throws on every method) — only consulted in Pattern B when no real impl is bound. Both patterns must dedupe on `$context['idempotency_key']`.

### Subscribable resolver contract

```
Middleware (subscribed / plan / feature)
  → Tashil::resolveSubscribable()
    → if a custom resolver is registered: $resolver() (host's Closure)
    → else: auth()->user()
    → returns the result iff it instanceof Subscribable, else null
  → middleware aborts 403 on null
```

Host overrides via `Tashil::resolveSubscribableUsing(fn () => Team::current())` in `AppServiceProvider::boot`.

## Gotchas

- **Don't infer trial conversion from payment.** Host policy — see invariant 10.
- **Don't `->update()` on `tashil_feature_usages`** except via `UsageService` — race-unsafe and skips logs + warning + event.
- **Don't write to `tashil_subscription_events` without `EventStore::append`** — bypasses sequence lock + idempotency check.
- **Don't dispatch domain events synchronously inside a `DB::transaction`** — wrap with `dispatchAfterCommit()`.
- **Renewal command does NOT charge or advance period** — see invariant 8. If a test expects period advance from `tashil:renew-subscriptions` alone, the test is wrong.
- **`tashil:reset-quotas` advances anchored to previous `period_end`** — never `now()`. See invariant 6.
- **Trial-aware `switchPlan` grants the NEW package's full trial**, not remaining days. Intentional. Hosts wanting "preserve days" call low-level `subscribe` with their own dates.
- **`switchPlan` requires `Subscription::subscriber` to resolve to a `Subscribable`.** If the host model doesn't implement the contract (or the morph target is missing), `switchPlan` throws `SubscriptionException::subscriberNotSubscribable` — fix the host model, don't try/catch around it.
- **Lifecycle errors throw `Foysal50x\Tashil\Exceptions\SubscriptionException`** (a `RuntimeException` subclass) via named constructors: `alreadySubscribed` (duplicate live sub on `subscribe`), `subscriberNotSubscribable` (`switchPlan`), `cannotProrateAcrossCurrencies` (`changePlan`). Catch the dedicated type, not message text.
- **Metered: don't call `UsageService::increment` directly without a snapshot.** The unit_price lives on `SubscriptionFeature`, not the catalog `Feature`. `consumeMetered` reads it from the current snapshot — keep snapshots intact.
- **Metered: trial subscriptions still charge by default.** Tashil makes no free-trial policy. If you want trial metering to be free, return `true` from `charge()` without debiting when `$subscription->isOnTrial()` — host policy.
- **`Tashil::resolveSubscribable()` returns null silently** when auth user doesn't implement `Subscribable`. Middleware handle this as 403; if you call the resolver directly in non-middleware code, check for null.
- **Static `subscribableResolver` on Tashil persists across requests.** Always call `forgetSubscribableResolver()` in test teardown to avoid bleed between tests.
- **`tashil.schedule.enabled = true` auto-wires across all Laravel versions** via `$this->app->booted(...)`. Manual wiring location differs (L10 `Kernel.php` / L11+ `routes/console.php` or `bootstrap/app.php`) — package code stays version-agnostic.

## Out of scope — don't add

- Card capture, payouts, refund execution, gateway sync.
- The actual retry *charge* during dunning, and webhook reconciliation logic. (Tahsil owns the dunning *state machine + schedule* — `tashil:process-dunning` — and fires the events; the host performs the charge. Don't add charging here.)
- Hash-chained financial ledger.
- Coupon / discount engine.
- Wallet / balance / account ledger — Metered features delegate to host via `MeteredBilling`. Don't introduce balance tables here.
- MRR waterfall (new/expansion/contraction/churn) beyond `AnalyticsService`.
- Cohort retention. **Cross-currency proration / FX normalization** — `changePlan` throws on a currency mismatch; don't add FX conversion.

If a request lands in these areas, push back: belongs in host or downstream warehouse, not Tashil.

## Quick reference

```php
use Foysal50x\Tashil\Contracts\Subscribable;
use Foysal50x\Tashil\Facades\Tashil;
use Foysal50x\Tashil\Traits\HasSubscriptions;

class User extends Authenticatable implements Subscribable
{
    use HasSubscriptions;
}

Tashil::feature('api-calls')->name('API Calls')->limit()->create();
Tashil::feature('ai-tokens')->name('AI Tokens')->metered()->create();
Tashil::package('pro')->name('Pro')->price(29)->monthly()->trialDays(14)
    ->feature($apiCalls, value: '10000')
    ->feature($aiTokens, value: '0.001')   // metered: unit_price USD/unit
    ->create();

// Priced plan → Pending until the initial invoice is paid (then auto-activates).
$sub = Tashil::subscription()->subscribe($user, $package);
Tashil::subscription()->subscribe($user, $package, withTrial: true); // OnTrial, access now
// Host charges → $invoice->markAsPaid() → InvoiceObserver → activate()

Tashil::subscription()->convertTrial($sub);                      // anchors + first invoice
Tashil::subscription()->changePlan($sub, $newPackage);           // in-place: upgrade prorates, downgrade defers
Tashil::subscription()->cancel($sub);                            // grace
Tashil::subscription()->switchPlan($sub, $newPackage);           // cancel old + new sub
Tashil::subscription()->scheduleDowngrade($sub, $targetPackage); // at period end
Tashil::subscription()->reactivate($sub);                        // recover a lapsed sub

$user->hasFeature('dark-mode');
$user->useFeature('api-calls', 1);          // atomic, returns false if over limit
$user->useFeature('ai-tokens', 1500);       // metered, returns false on insufficient balance
$user->reportStorage('storage-gb', 38.5);   // absolute value (rejects metered)

// Middleware
Route::middleware('subscribed')->group(fn () => /* ... */);
Route::middleware('plan:pro')->group(fn () => /* ... */);
Route::middleware('feature:api-calls')->group(fn () => /* ... */);

// Custom subscribable resolver (AppServiceProvider::boot)
Tashil::resolveSubscribableUsing(fn () => Team::current());

Tashil::events()->append($sub, 'host.custom', payload: [...], idempotencyKey: 'op-42');
Tashil::analytics()->dashboardSummary();
```
