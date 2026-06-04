<?php

declare(strict_types=1);

namespace App\Http\Controllers\Features;

use App\Http\Controllers\Controller;
use Foysal50x\Tashil\Facades\Tashil;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * FEATURE TYPE 3 of 5 — CONSUMABLE  (an uncapped counter)
 * ======================================================
 *
 * Catalog (CatalogSeeder):
 *     Tashil::feature('email-credits')->consumable()->resetMonthly()->create();
 *     // pro plan → ->feature($emailCredits, value: '5000')   value = the allowance
 *
 * Semantics vs LIMIT:
 *   - Same counter machinery, but NO hard cap enforcement. useFeature() always
 *     succeeds (the conditional UPDATE has no limit to check), so the counter
 *     can pass the attached allowance. limit_value on the counter is NULL.
 *   - You decide what "out of credits" means — typically: compare featureUsage
 *     against the plan's allowance yourself, then top up or charge overage.
 *   - resetMonthly() refills the allowance window each cycle (period anchored
 *     to the previous period_end, swept by tashil:reset-quotas).
 *
 * Use it for: included email/SMS credits, "soft" allowances you meter but don't
 * hard-block, anything where overage is billed rather than refused.
 */
class ConsumableFeatureExample extends Controller
{
    /**
     * POST /emails/send  — burn one credit per email. Always records; we apply
     * our OWN soft policy on top (warn / bill overage) rather than blocking.
     */
    public function sendEmail(Request $request): JsonResponse
    {
        $user  = $request->user();
        $count = (float) $request->integer('count', 1);

        // The plan allowance (the snapshot value) — what's "included".
        $allowance = (float) ($user->featureValue('email-credits') ?? 0);

        // Increment the consumable. Returns true here even past the allowance,
        // because consumables don't hard-cap.
        $user->useFeature('email-credits', $count);

        $used    = $user->featureUsage('email-credits');
        $overage = max(0.0, $used - $allowance);

        // Host-side soft policy: bill the overage / nudge to upgrade.
        if ($overage > 0) {
            // e.g. $this->billOverage($user, $overage * $perCreditRate);
        }

        return response()->json([
            'sent'      => $count,
            'used'      => $used,
            'allowance' => $allowance,
            'overage'   => $overage,
        ]);
    }

    /**
     * GET /emails/credits — a balance view computed against the allowance,
     * since the counter itself has no cap to subtract from.
     */
    public function credits(Request $request): JsonResponse
    {
        $user = $request->user();

        $allowance = (float) ($user->featureValue('email-credits') ?? 0);
        $used      = $user->featureUsage('email-credits');

        return response()->json([
            'allowance' => $allowance,
            'used'      => $used,
            'included_remaining' => max(0.0, $allowance - $used),
            'in_overage'         => $used > $allowance,
        ]);
    }

    /**
     * reportStorage(): the ABSOLUTE-value sibling of useFeature(). When the
     * caller already knows the total (a storage backend tells you "this account
     * uses 38.5 GB"), set it directly instead of computing deltas.
     *
     * - Accepts 0 (a valid absolute report); rejects negatives.
     * - Works on Limit and Consumable counters. It is REJECTED for Metered
     *   features (delta-charge model is incompatible with absolute-set) and for
     *   Boolean/Enum (no counter).
     */
    public function syncStorageUsage(Request $request): JsonResponse
    {
        $user      = $request->user();
        $totalGigs = (float) $request->input('gb_used');   // e.g. 38.5

        // Imagine 'email-credits' here were a consumable 'storage-gb' counter —
        // reportStorage sets the counter to the absolute total, writing a
        // 'report' usage log (and firing the 80% warning if a cap were set).
        $ok = $user->reportStorage('email-credits', $totalGigs);

        return response()->json([
            'accepted' => $ok,
            'value'    => $user->featureUsage('email-credits'),
        ]);
    }
}

/*
|------------------------------------------------------------------------------
| Reset & manual ops
|------------------------------------------------------------------------------
|
| Monthly refill happens automatically via tashil:reset-quotas. To refill or
| zero on demand (e.g. a top-up purchase resets the cycle):
|
|     Tashil::usage()->resetUsage($subscription, 'email-credits');
|
| Gating note: because a consumable never hard-blocks, the feature: middleware
| and @feature only check that the feature EXISTS on the plan — they won't stop
| a request for being "out of credits". Enforce that yourself with the
| allowance comparison shown above if you want a hard wall.
*/
