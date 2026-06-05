# Runnable Code Examples

Real-world, copy-paste-shaped example code for every Tashil flow and feature
type. The Markdown files one level up (`../01-Subscription-Management.md` …)
explain the *concepts*; this folder is the **code** — host controllers,
listeners, services, seeders, and scheduler wiring with inline comments.

Everything assumes the catalog from [`00-setup/CatalogSeeder.php`](00-setup/CatalogSeeder.php)
(slugs: `sso`, `api-calls`, `team-seats`, `email-credits`, `ai-tokens`,
`export-format`; packages: `free`, `pro`, `enterprise`).

> These files use a demo host namespace (`App\…`) and illustrative gateway/
> wallet helpers (`PaymentGateway`, `Wallet`, `wallet_charges`). They are
> teaching scaffolds — drop them into a Laravel app and wire your real gateway,
> not literal runnable-as-is package code.

## Start here — `00-setup/`

| File | What it shows |
|---|---|
| [`User.php`](00-setup/User.php) | Make a model `Subscribable` (`implements Subscribable` + `HasSubscriptions`); override `resolveSubscription()`. |
| [`CatalogSeeder.php`](00-setup/CatalogSeeder.php) | Define **one feature of every type** + three packages spanning the activation models. |
| [`WalletMeteredBilling.php`](00-setup/WalletMeteredBilling.php) | Implement `MeteredBilling` (charge-before-write, idempotent debit) for metered features. |
| [`UniqueInvoiceNumberGenerator.php`](00-setup/UniqueInvoiceNumberGenerator.php) | Custom id generator overriding `ShouldBeUnique` to widen the built-in uniqueness check to soft-deleted rows. |
| [`AppServiceProvider.php`](00-setup/AppServiceProvider.php) | Bind the metered provider, set the subscribable resolver, register the billing listeners. |

## Subscription lifecycle flows

### 1. Trial — `01-trial-subscription/`
| File | What it shows |
|---|---|
| [`TrialController.php`](01-trial-subscription/TrialController.php) | Full flow: start trial → status → **convert** (issues + pays first invoice) → cancel. |
| [`SendTrialEndingReminder.php`](01-trial-subscription/SendTrialEndingReminder.php) | Listeners for `TrialEnding` / `TrialConverted` / `TrialExpired`. |
| [`RegisterTrialCommands.php`](01-trial-subscription/RegisterTrialCommands.php) | Register `tashil:mark-trials-ending` + `tashil:expire-trials` (auto + manual). |

### 2. Paid invoice / activate-on-payment — `02-paid-invoice/`
| File | What it shows |
|---|---|
| [`CheckoutController.php`](02-paid-invoice/CheckoutController.php) | Subscribe a priced plan → `Pending` + initial invoice; hand the client a payment intent. |
| [`PaymentWebhookController.php`](02-paid-invoice/PaymentWebhookController.php) | Gateway webhook → `Tashil::billing()->recordPayment()` (records the transaction **and** settles + auto-activates, idempotent). |
| [`RefundController.php`](02-paid-invoice/RefundController.php) | Host refunds at the gateway → `Tashil::billing()->recordRefund()` (full/partial, flips the invoice to Refunded on a full refund). |
| [`ProvisionOnActivation.php`](02-paid-invoice/ProvisionOnActivation.php) | React to `SubscriptionActivated` to provision the account. |

### 3. Renewal — `03-renewal/`
| File | What it shows |
|---|---|
| [`ChargeRenewalInvoice.php`](03-renewal/ChargeRenewalInvoice.php) | `InvoiceIssued` (renewal) → charge saved card → `recordPayment()` → `advancePeriod()` (failed attempts logged via `recordFailedPayment()`). Plus the receipt listener. |
| [`RegisterRenewalCommand.php`](03-renewal/RegisterRenewalCommand.php) | Register `tashil:renew-subscriptions`; why the cron never advances the period itself. |

### 4. Suspend / dunning — `04-suspend/`
| File | What it shows |
|---|---|
| [`DunningListeners.php`](04-suspend/DunningListeners.php) | `SubscriptionPastDue` → retry charge; `SubscriptionSuspended` → revoke; `SubscriptionReactivated` → restore. |
| [`SuspensionController.php`](04-suspend/SuspensionController.php) | Admin manual **suspend** / **reactivate** + dunning status inspector. |
| [`RegisterDunningCommand.php`](04-suspend/RegisterDunningCommand.php) | Register `tashil:process-dunning` + the full `dunning.*` config and a worked timeline. |

### 5. Dashboard statistics — `05-dashboard-analytics/`
| File | What it shows |
|---|---|
| [`ReportingService.php`](05-dashboard-analytics/ReportingService.php) | **Date-range** revenue/signups/cancellations, **day/week/month** grouping (cross-db `DateFmt`), **due/overdue** filters. |
| [`DashboardController.php`](05-dashboard-analytics/DashboardController.php) | One endpoint, many filters: `?range=today|7d|this_week|this_month|this_year|custom&group=day|week|month`. |

## Feature types, one by one — `06-feature-types/`

| File | Type | Core call |
|---|---|---|
| [`01-BooleanFeatureExample.php`](06-feature-types/01-BooleanFeatureExample.php) | **Boolean** | `hasFeature('sso')` |
| [`02-LimitFeatureExample.php`](06-feature-types/02-LimitFeatureExample.php) | **Limit** | `useFeature('api-calls', n)` (atomic, capped) |
| [`03-ConsumableFeatureExample.php`](06-feature-types/03-ConsumableFeatureExample.php) | **Consumable** | `useFeature('email-credits', n)` (uncapped) + `reportStorage()` |
| [`04-MeteredFeatureExample.php`](06-feature-types/04-MeteredFeatureExample.php) | **Metered** | `useFeature('ai-tokens', n, $key)` (charge-before-write) |
| [`05-EnumFeatureExample.php`](06-feature-types/05-EnumFeatureExample.php) | **Enum** | `featureValue('export-format')` |

## Suggested reading order

```
00-setup  →  01-trial  →  02-paid-invoice  →  03-renewal  →  04-suspend
          →  05-dashboard  →  06-feature-types (Boolean → Limit → Consumable → Metered → Enum)
```

## The one mental model to keep

Tashil owns **subscription state, counters, invoicing, the transaction ledger,
and the dunning state machine**. It never moves money. Every "charge" / "refund"
in these examples is the host's gateway; you then tell Tashil what happened with
`Tashil::billing()->recordPayment()` / `recordFailedPayment()` / `recordRefund()`.
recordPayment routes through `Invoice::markAsPaid()` by invoice kind → `activate`
/ `advancePeriod` / `reactivate`. Metered features go through
`MeteredBilling::charge()`.
