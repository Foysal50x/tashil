# Developer Guide

How tahsil is laid out, how to extend it, and how to integrate it with a host application.

## Supported versions

| Laravel | PHP | Testbench | Pest | Collision |
|---|---|---|---|---|
| 10.x | 8.2 / 8.3 | ^8.0 | ^2.0 | ^7.0 |
| 11.x | 8.2 / 8.3 / 8.4 | ^9.0 | ^3.0 | ^8.0 |
| 12.x | 8.2 / 8.3 / 8.4 / 8.5 | ^10.0 | ^3.0 | ^8.0 |
| 13.x | 8.3 / 8.4 / 8.5 | ^11.0 | ^4.0 | ^8.0 |

Laravel 13 was released March 17, 2026 and requires PHP 8.3+. Tahsil's CI matrix tests every supported (Laravel × PHP × DB) combination — SQLite, MySQL 8, and PostgreSQL 16.

### Version-specific touchpoints

The package code itself is version-agnostic — it only depends on long-stable APIs (`Illuminate\Console\Scheduling\Schedule`, `Illuminate\Database\Eloquent\*`, `Illuminate\Support\Facades\*`, `Illuminate\Database\Migrations\Migration`). All six published commands wire identically across Laravel 10, 11, 12, and 13.

The only place a host developer has to think about Laravel versions is **manual scheduler wiring** — see [04-Scheduler-Jobs.md › Disabling auto-registration](04-Scheduler-Jobs.md#disabling-auto-registration). Quick reference:

| Laravel | Host wires schedule in |
|---|---|
| 10.x | `app/Console/Kernel.php` → `schedule(Schedule $schedule)` method |
| 11.x – 13.x | `routes/console.php` via `Schedule::command()` (recommended), or `bootstrap/app.php` via `->withSchedule()` |

Tahsil's own auto-registration (`tashil.schedule.enabled = true`) handles all versions transparently.


## Package layout

```
src/
  Builders/           PackageBuilder, FeatureBuilder — fluent catalog construction
  Console/            Six scheduled commands
  Contracts/          Subscribable, MeteredBilling, repository interfaces
  Enums/              SubscriptionStatus, FeatureType (incl. Metered), ResetPeriod,
                      UsageOperation, InvoiceStatus, TransactionStatus, Period
  Events/             Subscription / Trial / Invoice / Usage / Metered events
  Exceptions/         MeteredBillingNotConfiguredException
  Facades/            Tashil facade
  Http/Middleware/    EnsureSubscribed, EnsurePlan, EnsureFeature
  Managers/           DatabaseManager, CacheManager
  Models/             Eloquent models incl. immutable SubscriptionEvent /
                      SubscriptionFeature / FeatureUsage / UsageLog
  Observers/          InvoiceObserver (auto-number + paid-handler),
                      TransactionObserver (auto-id when blank)
  Providers/          TashilServiceProvider — registration + middleware + schedule
  Repositories/       EloquentXRepository + Cache decorators
  Services/           SubscriptionService, UsageService, BillingService,
                      AnalyticsService, EventStore, Resetter,
                      Providers/NullMeteredBilling
  Support/            DateFmt and friends
  Traits/             HasSubscriptions
  Tashil.php          Service facade with sub-accessors + resolveSubscribable

database/
  factories/          Model factories used by the test suite
  migrations/         Single migration that creates all tables

tests/
  Feature/            Integration tests (Metered, Middleware, SubscribableContract, ...)
  Unit/               Builder / Model / Enum tests
  Fixtures/           User fixture + FakeMeteredBilling for tests
```

## Layering

```
HasSubscriptions trait
        │
        ▼
    Tashil facade ──► SubscriptionService
                       UsageService
                       BillingService
                       AnalyticsService
                       EventStore
                              │
                              ▼
                  Repository interfaces (Contracts/)
                              │
                  Eloquent or Cache-decorated impls
                              │
                              ▼
                          Eloquent models
                              │
                              ▼
                            Database
```

Hot, frequently-mutating models (`SubscriptionEvent`, `SubscriptionFeature`, `FeatureUsage`) bypass the cache layer entirely — they go straight to Eloquent. Cold catalog data (`Package`, `Feature`) and aggregates (analytics queries) flow through cache decorators.

## Conventions

- **Strict typing on signatures**. Constructor property promotion preferred.
- **Transactions**. Every mutating service method wraps its work in `DB::transaction()` via the injected `DatabaseManager`. The closure must be idempotent on retry.
- **Events fire after commit**. `Service::dispatchAfterCommit()` uses `DB::afterCommit()` when `tashil.events.async = true`. Listeners never see torn state.
- **Append-only models throw on update/delete**. `SubscriptionEvent` rejects everything; `SubscriptionFeature` allows only `superseded_at` to change.
- **Float for usage**. `tashil_feature_usages.usage` is `DECIMAL(20,4)`; the model casts it to `float`. Pass `float` amounts to `increment()` / `reportStorage()`; receive `float` back from `featureUsage()` / `featureRemaining()`.

## Extension points

### 1. Custom event listeners

Register in your host's `EventServiceProvider`:

```php
protected $listen = [
    \Foysal50x\Tashil\Events\SubscriptionCreated::class       => [SendWelcomeEmail::class],
    \Foysal50x\Tashil\Events\SubscriptionRenewed::class       => [PushRenewalToCRM::class],
    \Foysal50x\Tashil\Events\SubscriptionCancelled::class     => [TriggerExitSurvey::class],
    \Foysal50x\Tashil\Events\TrialEnding::class               => [SendTrialNudge::class],
    \Foysal50x\Tashil\Events\InvoicePaid::class               => [RecordPaymentInCRM::class],
    \Foysal50x\Tashil\Events\UsageLimitWarning::class         => [NotifyApproachingLimit::class],
];
```

A full event reference lives in [05-Reporting-Data-Model.md](05-Reporting-Data-Model.md).

### 2. Custom id generation (invoices & transactions)

Both `tashil_invoices.invoice_number` and `tashil_transactions.transaction_id` are stamped by an observer (`InvoiceObserver::creating`, `TransactionObserver::creating`) when the column is empty. Each observer resolves its generator class from config:

```php
// config/tashil.php
'invoice' => [
    'prefix'    => 'INV',
    'format'    => '#-YYMMDD-NNNNNN',
    'generator' => InvoiceNumberGenerator::class,
],
'transaction' => [
    'prefix'    => 'TXN',
    'format'    => '#-YYMMDD-NNNNNNAA',
    'generator' => TransactionIdGenerator::class,
],
```

The transaction observer only fires when the row is created without a `transaction_id` — gateway-driven flows that already have a Stripe / Paddle / … id should pass it through and the observer leaves it alone. The auto-id path covers cases like an admin recording a cash payment.

Both built-in generators share `TokenizedIdGenerator`, an abstract base that owns the token grammar (`#`, `YY`, `MM`, `DD`, `N`, `S`, `A`) and the `generate()` body. Subclasses declare only which config keys to read:

```php
namespace Foysal50x\Tashil\Services\Generators;

class MyInvoiceNumberGenerator extends TokenizedIdGenerator
{
    protected function prefix(): string
    {
        return (string) config('tashil.invoice.prefix', 'INV');
    }

    protected function format(): string
    {
        return (string) config('tashil.invoice.format', '#-YYMMDD-NNNNNN');
    }
}
```

If you need a wholly different scheme (e.g. a Stripe-style `in_xxx` prefix-and-base58), skip the base class and implement `generate(): string` directly — the observers only require that method:

```php
class StripeStyleGenerator
{
    public function generate(): string
    {
        return 'in_' . bin2hex(random_bytes(12));
    }
}
```

Format-token entropy matters at bulk-insert time. The defaults give ~1M (`NNNNNN`) and ~1.3B (`NNNNNNAA`) combinations per `YYMMDD` bucket; the transaction default is wider because gateways can drop several hundred webhooks per minute. Widen the format if you expect more than ~√combos rows on a single day or you will hit birthday collisions. The composite `UNIQUE(gateway, transaction_id)` is the last line of defence; a colliding generator output will surface as a `UniqueConstraintViolationException` rather than silent corruption.

### 3. Custom repository implementations

Every persistence concern lives behind an interface in `src/Contracts/`. To swap one, bind in a service provider:

```php
$this->app->bind(
    \Foysal50x\Tashil\Contracts\SubscriptionRepositoryInterface::class,
    \App\Repositories\MyEventSourcedSubscriptionRepository::class,
);
```

Tahsil's bindings happen in `register()` so any host-side override in `boot()` wins.

### 4. Custom scheduler cadence

```php
// config/tashil.php
'schedule' => [
    'enabled' => true,
    'overrides' => [
        'tashil:expire-trials'    => '*/10 * * * *',
        'tashil:reset-quotas'     => '0 1 * * *',
    ],
],
```

Or disable auto-registration and wire commands yourself in `app/Console/Kernel.php`.

### 5. Adding a new feature type

1. Add a case to `FeatureType` enum.
2. If it needs a counter, the existing `FeatureUsage` row will handle it — set `limit_value` and `reset_period` appropriately on subscribe.
3. Extend `UsageService::check()` and `increment()` to handle the new type if its semantics differ from existing types.
4. Update the snapshot logic in `EloquentSubscriptionRepository::syncFeatures()` if it needs extra fields.

### 6. Triggering custom events on subscription transitions

The `EventStore` is part of the public API surface:

```php
$store = Tashil::events();

$store->append($subscription, 'host.custom.thing', [
    'foo' => 'bar',
], idempotencyKey: 'host-custom-' . $request->id);
```

Use the idempotency key when the operation can be retried by an outer system (queued job, HTTP retry).

## Integrating tahsil with the host's billing / payments

The contract: tahsil owns subscription state and invoice issuance; the host owns money movement.

```
┌─────────────────────────────────────────────────────────────┐
│  Tahsil                                                     │
│  - Issues Invoice (status=pending)                          │
│  - Fires InvoiceIssued                                      │
├─────────────────────────────────────────────────────────────┤
│  Host listener                                              │
│  - Charges card via Stripe/Paddle/…                         │
│  - On success → $invoice->markAsPaid()                      │
├─────────────────────────────────────────────────────────────┤
│  Tahsil InvoiceObserver                                     │
│  - On status → Paid:                                        │
│    - SubscriptionService::advancePeriod($sub)               │
│    - Append subscription.renewed                            │
│    - Dispatch SubscriptionRenewed + InvoicePaid             │
└─────────────────────────────────────────────────────────────┘
```

For dunning, gateway webhook reconciliation, refund execution, the host wires the equivalent listeners. Tahsil's job ends at issuing the bill and reflecting the host's `markAsPaid` decision.

When recording transactions, pass the gateway-supplied id through (Stripe `ch_…`, Paddle `txn_…`, etc.). The `UNIQUE(gateway, transaction_id)` constraint on `tashil_transactions` makes duplicate webhook deliveries safe — a second insert with the same pair throws `UniqueConstraintViolationException`, which the host should treat as "already recorded, ignore." For non-gateway payments (cash, manual bank transfer), leave `transaction_id` empty and `TransactionObserver::creating` will stamp a `TXN-…` id from `tashil.transaction.generator`.

## Adding the trait to a model

```php
use Foysal50x\Tashil\Contracts\Subscribable;
use Foysal50x\Tashil\Traits\HasSubscriptions;

class User extends Authenticatable implements Subscribable
{
    use HasSubscriptions;
}
```

The `implements Subscribable` is **required** — every library type-hint that takes a subscriber is `Subscribable`, not Eloquent's `Model`. The trait provides default implementations for all four interface methods (`subscriptions`, `resolveSubscription`, `getSubscriberKey`, `getSubscriberType`); you only need to override the ones you want to change.

That gives the model the full API documented in [examples/01-Subscription-Management.md](../examples/01-Subscription-Management.md) and [examples/02-Feature-Usage-Tracking.md](../examples/02-Feature-Usage-Tracking.md). Any Eloquent model can be a subscriber — `Team`, `Organization`, `Workspace`.

### Customising which subscription is "the active one"

`HasSubscriptions::loadSubscription()` calls `resolveSubscription()` to decide which subscription to use for every feature / lifecycle / middleware check. Default = latest valid subscription. Override for multi-sub / tenant scenarios:

```php
class Team extends Model implements Subscribable
{
    use HasSubscriptions;

    public function resolveSubscription(): ?Subscription
    {
        // Example: prefer the subscription tied to the team's current
        // workspace rather than the most recently created one.
        return $this->subscriptions()
            ->valid()
            ->where('package_id', $this->currentWorkspace->preferredPackageId())
            ->first();
    }
}
```

### Resolving the subscribable for middleware / Blade directives

Tahsil's middleware and helpers need to know which model represents "the current subscriber" — by default, the authenticated user. Override when it's a team, tenant, or any other model:

```php
// AppServiceProvider::boot
use Foysal50x\Tashil\Facades\Tashil;

Tashil::resolveSubscribableUsing(fn () => Team::current());
```

`Tashil::resolveSubscribable()` returns `null` when no resolver is registered and `auth()->user()` is unauthenticated, or when the resolved object doesn't implement `Subscribable`. The middleware treat both cases as "deny → 403".

## Middleware

Three route middleware ship with the package and auto-register on boot:

| Alias | Class | Purpose |
|---|---|---|
| `subscribed` | `EnsureSubscribed` | Resolved subscribable has a currently valid subscription. |
| `plan:{slug}` | `EnsurePlan` | Subscribed AND on the named package slug. |
| `feature:{slug}` | `EnsureFeature` | Subscribed AND `UsageService::check` passes for the named feature. |

```php
Route::middleware('subscribed')->group(function () {
    // any valid subscription
});

Route::middleware('plan:pro')->group(function () {
    // Pro plan only
});

Route::middleware('feature:api-calls')->group(function () {
    // requires the api-calls feature on the current snapshot
});
```

All three abort with `403` on failure. Override aliases via `config('tashil.middleware.aliases')` when a name collides with an existing host alias.

## Blade directives

Four conditional directives are registered on boot. They go through the same `Tashil::resolveSubscribable()` resolver as the middleware, so a custom `resolveSubscribableUsing` callback affects views and routes uniformly.

```blade
@subscribed
    {{-- valid subscription (Active / OnTrial / PendingCancellation in grace) --}}
@endsubscribed

@plan('pro')
    {{-- subscribed AND on this package slug --}}
@endplan

@feature('api-calls')
    {{-- UsageService::check passes for this feature on the current snapshot --}}
@endfeature

@onTrial
    {{-- strict: status == OnTrial AND trial_ends_at in future --}}
@endonTrial
```

All directives support `@else` and `@endX`. They degrade safely to `false` when no subscribable can be resolved (guest user, no override, or the resolved object doesn't implement `Subscribable`).

## Metered billing

`MeteredBilling` is the host-implemented bridge to the wallet / balance system that funds Metered features. Tashil's default binding is `NullMeteredBilling`, which throws on every call so misconfiguration fails loud.

Two implementation patterns are supported with no flag to toggle between them — `UsageService::resolveMeteredBilling` checks each subscriber per consume:

1. **Self-implementing model** — `class User implements Subscribable, MeteredBilling`. No container binding needed; the subscriber handles its own billing.
2. **Standalone class** — bound via the service container. Suited to service-layered codebases or shared billing logic across subscriber types.

Both patterns satisfy the same interface and trigger the same events. Hosts can mix them: one subscriber type self-implements, another delegates to the container binding — resolution is per-subscriber.

See [02-Feature-System.md › Metered features](02-Feature-System.md#metered-features) for the full contract, both patterns side-by-side, and the idempotency requirements.

## Testing your integration

Pest is configured. Use the package's own pattern:

```php
use Foysal50x\Tashil\Tests\Fixtures\User;   // not exported — copy or build your own
use Foysal50x\Tashil\Models\Package;
use Foysal50x\Tashil\Models\Feature;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__ . '/../Fixtures/create_users_table.php');
});

it('does the host thing on trial expiry', function () {
    Event::fake([\Foysal50x\Tashil\Events\TrialExpired::class]);

    $package = Tashil::package('pro')->name('Pro')->price(10)->monthly()
        ->trialDays(7)->create();

    $user = User::create(['name' => 'A', 'email' => 'a@a.com']);
    $sub  = Tashil::subscription()->subscribe($user, $package, withTrial: true);

    // Travel past trial.
    $this->travel(8)->days();
    $this->artisan('tashil:expire-trials')->assertSuccessful();

    Event::assertDispatched(\Foysal50x\Tashil\Events\TrialExpired::class);
});
```

Tahsil's own suite uses real DB + real time travel. The fixtures `tests/Fixtures/User.php` and `tests/Fixtures/create_users_table.php` are the reference setup; copy them into your host test scaffolding.

## Database connection isolation

```env
TASHIL_DB_CONNECTION=tashil
```

Combined with `config/database.php` defining a `tashil` connection lets you keep tahsil tables on a separate DB or schema. All models, repositories, and the migration honor this connection.

## Cache isolation

A dedicated Redis store named `tashil` is auto-registered (see `TashilServiceProvider::registerCacheStore`). Tune via:

```env
TASHIL_REDIS_HOST=…
TASHIL_REDIS_DB=5
TASHIL_CACHE_PREFIX=tashil_cache:
TASHIL_CACHE_TTL=3600
TASHIL_CACHE_ENABLED=true
```

Disabling cache (`TASHIL_CACHE_ENABLED=false`) makes every repository read hit the DB. Useful for local development and for debugging stale-cache issues.

## Where things are tested

| What | Test file |
|---|---|
| Subscribe → events written → snapshot+counter written | `tests/Feature/SubscriptionFlowTest.php` |
| Grace cancellation keeps `subscribed()` true | `tests/Feature/SubscriptionFlowTest.php` |
| EventStore monotonic sequence + idempotency + immutability | `tests/Feature/EventStoreTest.php` |
| Atomic usage increment, 80% threshold, reset, storage report | `tests/Feature/UsageTrackingTest.php` |
| Renewal / expiration / trial-expire commands | `tests/Feature/ProcessSubscriptionsTest.php` |
| Bug fixes #1–#14 from the design doc | `tests/Feature/SubscriptionFlowTest.php` + `tests/Unit/Models/SubscriptionStatusHelpersTest.php` |
| Analytics (MRR, churn, dashboard) | `tests/Feature/AnalyticsServiceTest.php`, `DashboardSummaryTest.php`, `PackageAnalyticsTest.php` |
| Trait surface | `tests/Feature/HasSubscriptionsTraitTest.php` + `HasSubscriptionsAdditionalTest.php` |

Run all tests: `composer test` (or `./vendor/bin/pest`).
