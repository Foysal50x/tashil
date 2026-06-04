<?php

namespace Foysal50x\Tashil\Enums;

/**
 * What an invoice bills for. Drives InvoiceObserver routing on payment:
 *
 *  - Initial:   first charge for a `pending` subscription → activate().
 *  - Renewal:   recurring charge for an active subscription → advancePeriod().
 *  - Proration: mid-cycle upgrade delta → no period change (already mid-period).
 *  - Usage:     postpaid metered usage rolled up for a period → no period change.
 */
enum InvoiceKind: string
{
    case Initial = 'initial';
    case Renewal = 'renewal';
    case Proration = 'proration';
    case Usage = 'usage';
}
