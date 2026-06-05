<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Foysal50x\Tashil\Facades\Tashil;
use Foysal50x\Tashil\Models\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADMIN-DRIVEN SUSPEND / REACTIVATE — the manual counterpart to automatic
 * dunning (DunningListeners.php). Use this for fraud holds, abuse, chargebacks,
 * or a support agent acting on a request.
 *
 * Two ways a subscription gets reactivated:
 *   A) the customer pays the overdue invoice → InvoiceObserver → reactivate()
 *      happens automatically (no admin action needed).
 *   B) an admin force-reactivates (e.g. after resolving a dispute) →
 *      Tashil::subscription()->reactivate($sub).
 *
 * reactivate() is a no-op unless the subscription is actually lapsed
 * (PastDue / Suspended / Expired) — you can't "reactivate" a healthy Active sub.
 */
class SuspensionController extends Controller
{
    /**
     * POST /admin/subscriptions/{subscription}/suspend
     *
     * Immediately cut access. Status → Suspended, suspended_at = now. Fires
     * SubscriptionSuspended, so RevokeAccessOnSuspend tears down API keys etc.
     */
    public function suspend(Subscription $subscription): JsonResponse
    {
        // Only meaningful for a subscription that currently has access.
        if (! $subscription->isValid()) {
            return response()->json([
                'message' => 'Subscription is not in an access-granting state.',
                'status'  => $subscription->status->value,
            ], 422);
        }

        $subscription = Tashil::subscription()->suspend($subscription);

        return response()->json([
            'status'       => $subscription->status->value,   // "suspended"
            'suspended_at' => $subscription->suspended_at,
            'has_access'   => $subscription->isValid(),        // false
        ]);
    }

    /**
     * POST /admin/subscriptions/{subscription}/reactivate
     *
     * Restore access to a lapsed subscription. Clears dunning_attempts and
     * suspended_at; keeps a still-future period or starts a fresh one. Fires
     * SubscriptionReactivated.
     */
    public function reactivate(Subscription $subscription): JsonResponse
    {
        if (! $this->isLapsed($subscription)) {
            return response()->json([
                'message' => 'Only past-due, suspended, or expired subscriptions can be reactivated.',
                'status'  => $subscription->status->value,
            ], 422);
        }

        $subscription = Tashil::subscription()->reactivate($subscription);

        return response()->json([
            'status'           => $subscription->status->value,   // "active"
            'dunning_attempts' => $subscription->dunning_attempts, // 0
            'period_end'       => $subscription->current_period_end,
            'has_access'       => $subscription->isValid(),         // true
        ]);
    }

    /**
     * GET /admin/subscriptions/{subscription}/dunning
     *
     * Inspect where a subscription sits in the recovery lifecycle, including
     * the unpaid invoice that triggered it.
     */
    public function status(Subscription $subscription): JsonResponse
    {
        $overdue = Tashil::billing()->overdueInvoice($subscription);

        return response()->json([
            'status'           => $subscription->status->value,
            'is_past_due'      => $subscription->isPastDue(),
            'is_suspended'     => $subscription->isSuspended(),
            'is_expired'       => $subscription->isExpired(),
            'dunning_attempts' => $subscription->dunning_attempts,
            'last_dunning_at'  => $subscription->last_dunning_at,
            'suspended_at'     => $subscription->suspended_at,
            'has_access'       => $subscription->isValid(),
            'overdue_invoice'  => $overdue ? [
                'id'           => $overdue->id,
                'amount'       => $overdue->amount,
                'due_date'     => $overdue->due_date,
                'days_overdue' => (int) $overdue->due_date->diffInDays(now()),
            ] : null,
        ]);
    }

    private function isLapsed(Subscription $subscription): bool
    {
        return $subscription->isPastDue()
            || $subscription->isSuspended()
            || $subscription->isExpired();
    }
}
