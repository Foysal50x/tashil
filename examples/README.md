# Examples / Documentation

This folder contains detailed examples and documentation for using the `tahsil` package.

## Two ways in

- **[`code/`](./code/) — runnable code examples.** Real host controllers,
  listeners, services, seeders, and scheduler wiring with inline comments. One
  complete flow per subscription scenario (trial, paid invoice, renewal,
  suspend), a date-filtered analytics dashboard, and one example per feature
  type (Boolean, Limit, Consumable, Metered, Enum). Start at
  [`code/README.md`](./code/README.md).
- **The Markdown files below — concept docs.** The "why" and the contracts
  behind those flows.

## Contents

1. [Subscription Management](./01-Subscription-Management.md)
   - Subscribe a user (activate-on-payment: `Pending` → pay → `Active`)
   - Cancel / Resume subscriptions
   - Change plan (in-place + proration) vs. switch plan
   - Past-due / suspension / reactivation (dunning)
   - Check subscription status

2. [Feature Usage Tracking](./02-Feature-Usage-Tracking.md)
   - Defining features (Limit vs Boolean)
   - Checking access
   - Incrementing usage
   - Reseting usage

3. [Billing and Invoicing](./03-Billing-and-Invoicing.md)
   - Invoice kinds (initial / renewal / proration) and generation logic
   - Flexible Invoice Numbering configuration
   - Invoice status flow — `markAsPaid()` drives activate / renew / reactivate

4. [Analytics](./04-Analytics.md)
   - Dashboard stats
   - Package-level analytics
   - MRR calculation

5. [Console Commands](./05-Console-Commands.md)
   - Processing renewals (`tashil:renew-subscriptions`)
   - Dunning escalation (`tashil:process-dunning`)
   - Handling expirations and trials
