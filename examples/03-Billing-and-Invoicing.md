# Billing and Invoicing

## 1. Invoice Generation

Invoices are automatically generated when:

1. A new subscription is created (if not a trial).
2. A subscription renews via the scheduled command.

You can also manually generate an invoice:

```php
$invoice = app('tashil')->billing()->generateInvoice($subscription);
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
`Pending` -> `Paid` or `Overdue` -> `Cancelled`

```php
use Foysal50x\Tashil\Enums\InvoiceStatus;

$invoice->update(['status' => InvoiceStatus::Paid, 'paid_at' => now()]);
```
