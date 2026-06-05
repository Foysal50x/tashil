# Billing and Invoicing

Tahsil owns invoice **state** and the **transaction ledger**, never money movement. It issues invoices, records the payments/refunds your gateway reports, and reacts to invoices being paid; the host's gateway performs the actual charge and refund.

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

### Guaranteed-unique numbers

The built-in generator already implements `ShouldBeUnique` — it pre-checks the rendered `invoice_number` against the live table and re-renders on a hit (the DB unique constraint remains the real guarantee; the host owns retry on an actual collision). Point `generator` at your own class to change the *scope* of that check — e.g. include soft-deleted rows — by extending `TokenizedIdGenerator` **and** implementing `ShouldBeUnique`. `generate()` re-renders until your `isUnique()` accepts the id, or throws `UniqueIdGenerationException` once the attempt budget is exhausted:

```php
use Foysal50x\Tashil\Contracts\ShouldBeUnique;
use Foysal50x\Tashil\Models\Invoice;
use Foysal50x\Tashil\Services\Generators\TokenizedIdGenerator;

class UniqueInvoiceNumberGenerator extends TokenizedIdGenerator implements ShouldBeUnique
{
    protected function prefix(): string { return (string) config('tashil.invoice.prefix', 'INV'); }
    protected function format(): string { return (string) config('tashil.invoice.format', '#-YYMMDD-NNNNNN'); }

    public function isUnique(string $id): bool
    {
        return ! Invoice::withTrashed()->where('invoice_number', $id)->exists();
    }
}

// config/tashil.php → 'invoice' => ['generator' => \App\Billing\UniqueInvoiceNumberGenerator::class]
```

Full runnable version: [code/00-setup/UniqueInvoiceNumberGenerator.php](./code/00-setup/UniqueInvoiceNumberGenerator.php).

## 3. Invoice Status Flow

Invoices typically follow this flow:
`Pending` -> `Paid` or `Overdue` -> `Cancelled` / `Void`

**Record a payment with `recordPayment()`** — don't hand-write the status, and don't hand-roll the `Transaction` + `markAsPaid()` dance. `recordPayment()` writes the transaction audit row **and** marks the invoice paid in one DB transaction, which lets `InvoiceObserver` route the subscription side effect based on the invoice `kind` and the subscription's current status:

```php
// Records the Transaction + settles the invoice (idempotent on gateway+id).
app('tashil')->billing()->recordPayment(
    $invoice,
    gateway: 'stripe',
    transactionId: $charge->id,   // null for cash/manual → a TXN-… id is stamped
);
```

`markAsPaid()` is still available as the low-level trigger if you record the transaction yourself, but `recordPayment()` is the complete path (see §4):

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

## 4. Transactions — the money ledger

An invoice is the *bill*; a **transaction** is the record of a payment attempt against it (`tashil_transactions`, `Invoice::transactions()`). Tahsil never charges a card — your gateway does — but it gives you three methods on `billing()` to record what your gateway reports, each writing the audit row and reflecting the invoice state so the two never drift apart:

| Method | Records | Invoice effect |
|---|---|---|
| `recordPayment($invoice, …)` | a `success` transaction | `markAsPaid()` → activate / advance / reactivate |
| `recordFailedPayment($invoice, …)` | a `failed` transaction | none — left `Pending` for dunning |
| `recordRefund($transaction, …)` | refund on the original transaction | `Refunded` on a **full** refund only |

```php
$billing = app('tashil')->billing();

// A successful charge — settles + auto-activates/renews/reactivates.
$txn = $billing->recordPayment(
    $invoice,
    amount: 30.00,                       // defaults to the invoice amount
    gateway: 'stripe',
    transactionId: 'ch_3P…',             // gateway id; null → stamps a TXN-… id
    gatewayResponse: $payload,           // stored as JSON for reconciliation
    metadata: ['source' => 'webhook'],
);

// A declined charge — audit only; the invoice stays Pending for dunning.
$billing->recordFailedPayment($invoice, gateway: 'stripe', gatewayResponse: ['decline_code' => 'insufficient_funds']);
```

**Idempotency is built in.** `recordPayment()` / `recordFailedPayment()` dedupe on the `UNIQUE(gateway, transaction_id)` key — a re-delivered, at-least-once webhook resolves to the row already written and never settles the invoice twice. Pass the gateway id verbatim; leave it null only for cash/manual entries (then `TransactionObserver` stamps a `TXN-…` id, with uniqueness checked within that gateway — the same id can exist under another gateway).

Events: `PaymentRecorded`, `PaymentFailed` (and `PaymentRefunded`, below) carry the transaction + invoice and fire after commit. `recordPayment()` also fires the existing `InvoicePaid` via the observer.

## 5. Refunds

The host refunds at the gateway, then records it. Tahsil never issues a gateway refund:

```php
// 1. Your gateway moves the money.
$gateway->refund($txn->transaction_id, 12.50);

// 2. Tahsil records it against the original transaction.
$billing->recordRefund(
    $txn,
    amount: 12.50,                       // omit for a full refund of the remaining balance
    reason: 'customer request',
);
```

`recordRefund()` accumulates `refunded_amount` (so partial refunds add up), stamps `refunded_at` + `refund_reason`, and fires `PaymentRefunded`. A **partial** refund keeps the transaction `Success` and the invoice `Paid`; once the cumulative refund reaches the full charge the transaction flips to `Refunded` and the invoice moves to `Refunded`. Refunding more than the refundable balance, or refunding a non-successful transaction, throws `InvalidArgumentException`.

Full runnable controllers: [code/02-paid-invoice/PaymentWebhookController.php](./code/02-paid-invoice/PaymentWebhookController.php) and [code/02-paid-invoice/RefundController.php](./code/02-paid-invoice/RefundController.php).
