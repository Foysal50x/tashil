# Console Commands

Automate subscription lifecycle events using Artisan commands.

## Process Subscriptions

This command handles **Renewals** and **Expirations**.

```bash
php artisan tahsil:process-subscriptions
```

### What it does

1. **Renewals**:
   - Finds active subscriptions with `auto_renew = true` that expire **today**.
   - Generates a new `Pending` invoice for the renewal.
   - If a pending invoice already exists, it may cancel the subscription (to prevent double billing/orphaned states) depending on logic.

2. **Expirations**:
   - Finds active subscriptions with `auto_renew = false` that expire **today**.
   - Marks them as `Expired`.

### Scheduling

Add this to your application's `console.php` or `Isolator` schedule to run daily:

```php
$schedule->command('tashil:process-subscriptions')->daily();
```
