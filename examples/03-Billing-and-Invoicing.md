# Billing and Invoicing

Tahsil owns invoice **state**, never money movement. It issues invoices and reacts to them being paid; the host's gateway performs the actual charge and calls `markAsPaid()`.

## 1. Invoice Generation

Every invoice carries a `kind` (`Foysal50x\Tashil\Enums\InvoiceKind`) that tells the `InvoiceObserver` what paying it should do:

| Kind | Issued when | Paying it… |
|---|---|---|
| `Initial` | a priced, payment-required plan is subscribed (or a trial converts) | activates the subscription, anchoring the period to `paid_at` |
| `Renewal` | the period elapsed and `tashil:renew-subscriptions` ran | advances the period and fires `SubscriptionRenewed` |
| `Proration` | an in-place `changePlan()` upgrade bills the mid-period delta | records payment; period unchanged |

So invoices appear automatically when:

1. A priced plan is subscribed → an `Initial` invoice (free / `requires_payment = false` plans and trials get none at subscribe).
2. A subscription renews via `tashil:renew-subscriptions` → a `Renewal` invoice.
3. An upgrade is prorated via `changePlan()` → a `Proration` invoice.

You can also generate one manually. The default `kind` is `Renewal`:

```php
use Foysal50x\Tashil\Enums\InvoiceKind;

$invoice = app('tashil')->billing()->generateInvoice($subscription);

// or an explicit kind / amount / due window:
$invoice = app('tashil')->billing()->generateInvoice(
    $subscription,
    amount: 49.00,
    kind: InvoiceKind::Renewal,
);

// the initial invoice for a gated subscription (due window from
// tashil.billing.initial_invoice_due_days) is issued via:
$invoice = app('tashil')->billing()->issueInitialInvoice($subscription);
```

## 2. Flexible Invoice Numbering

Configure the invoice number format in `config/tashil.php`.

```php
'invoice' => [
    'prefix'    => 'INV',
    'format'    => '#-YYMMDD-NNNNNN', 
    'generator' => Foysal50x\Tashil\Services\Generators\InvoiceNumberGenerator::class,
],
```

### Supported Tokens

- `#`: Prefix (e.g., `INV`)
- `YY`: Year (2 digits)
- `MM`: Month (2 digits)
- `DD`: Day (2 digits)
- `N`: Random Digit (0-9)
- `S`: Random Letter (A-Z)
- `A`: Random Alphanumeric (0-9, A-Z)

**Example:**
Format `INV-#-YY-NNN` results in `INV-INV-23-481`.
Format `#-YYMMDD-NNNNNN` results in `INV-231125-849021`.

## 3. Invoice Status Flow

Invoices typically follow this flow:
`Pending` -> `Paid` or `Overdue` -> `Cancelled` / `Void`

**Record a payment with `markAsPaid()`** — don't hand-write the status. It stamps `paid_at` and, crucially, lets `InvoiceObserver` route the subscription side effect based on the invoice `kind` and the subscription's current status:

```php
$invoice->markAsPaid();
```

| Invoice paid | Subscription state | Effect |
|---|---|---|
| `Initial` | `Pending` | `activate()` — period anchored to `paid_at` |
| `Renewal` | `Active` / `OnTrial` | `advancePeriod()` + `SubscriptionRenewed` |
| any | `PastDue` / `Suspended` / `Expired` (lapsed) | `reactivate()` + `SubscriptionReactivated` |
| `Proration` | `Active` | payment recorded; period unchanged |

`markAsPaid()` is idempotent on the subscription side — an `Initial` invoice never advances the period, so paying it twice can't double-bill the calendar. To void instead of pay:

```php
$invoice->markAsVoid(); // status: Void
```
