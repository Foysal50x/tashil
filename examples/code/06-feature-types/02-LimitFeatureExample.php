<?php

declare(strict_types=1);

namespace App\Http\Controllers\Features;

use App\Http\Controllers\Controller;
use Foysal50x\Tashil\Facades\Tashil;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * FEATURE TYPE 2 of 5 — LIMIT  (a capped counter)
 * ===============================================
 *
 * Catalog (CatalogSeeder):
 *     Tashil::feature('api-calls')->limit()->resetMonthly()->create();   // resets
 *     Tashil::feature('team-seats')->limit()->resetPeriod(Never)->create(); // no reset
 *     // pro plan → ->feature($apiCalls, value: '50000')   value = the cap
 *     //          → ->feature($seats,    value: '10')
 *
 * Semantics:
 *   - A counter with a per-plan cap (the snapshot value).
 *   - useFeature() increments ATOMICALLY via a conditional UPDATE: if the
 *     increment would exceed the cap it returns false and the counter is
 *     untouched (race-safe — no read-then-write window). This is the whole
 *     point of the type: you can't oversell the quota under concurrency.
 *   - UsageLimitWarning fires ONCE per period the first time usage crosses 80%.
 *   - Resetting: 'api-calls' auto-resets each month via tashil:reset-quotas;
 *     'team-seats' (reset Never) only changes when you change it.
 *
 * Use it for: API quotas, monthly build minutes, seats, projects, documents.
 */
class LimitFeatureExample extends Controller
{
    /**
     * POST /api/call  — consume one unit of a resetting quota.
     *
     * This is the canonical metered-action pattern: try to consume, and if the
     * quota is exhausted, refuse the work with 429.
     */
    public function makeApiCall(Request $request): JsonResponse
    {
        $user = $request->user();

        // Atomic increment-or-reject. Returns false when over the cap (or the
        // subscription is invalid / the feature isn't on the plan).
        if (! $user->useFeature('api-calls', 1)) {
            return response()->json([
                'message'   => 'Monthly API quota exhausted.',
                'used'      => $user->featureUsage('api-calls'),
                'remaining' => 0,
            ], 429);
        }

        // ... do the actual API work ...

        return response()->json([
            'ok'        => true,
            'used'      => $user->featureUsage('api-calls'),     // current counter
            'remaining' => $user->featureRemaining('api-calls'), // cap − usage (null if unlimited)
        ]);
    }

    /**
     * Consume a BATCH atomically. Passing the amount means the whole batch is
     * accepted or rejected together — you never half-consume past the cap.
     */
    public function makeBatchCalls(Request $request): JsonResponse
    {
        $user  = $request->user();
        $count = (int) $request->integer('count', 1);

        // Pre-flight check (read-only, no mutation) if you want to tell the
        // user before attempting:
        if (! Tashil::usage()->check($user->subscription(), 'api-calls', $count)) {
            return response()->json([
                'message'   => "Not enough quota for {$count} calls.",
                'remaining' => $user->featureRemaining('api-calls'),
            ], 429);
        }

        // The authoritative gate is still useFeature — check() can race, the
        // atomic increment cannot.
        if (! $user->useFeature('api-calls', $count)) {
            return response()->json(['message' => 'Quota exhausted mid-batch.'], 429);
        }

        return response()->json(['ok' => true, 'remaining' => $user->featureRemaining('api-calls')]);
    }

    /**
     * GET /api/quota — show usage with a percentage for a usage meter UI.
     */
    public function quota(Request $request): JsonResponse
    {
        $user = $request->user();

        $used      = $user->featureUsage('api-calls');
        $remaining = $user->featureRemaining('api-calls');   // null when unlimited
        $cap       = $remaining === null ? null : $used + $remaining;

        return response()->json([
            'used'      => $used,
            'cap'       => $cap,
            'remaining' => $remaining,
            'percent'   => $cap ? round($used / $cap * 100, 1) : null,
        ]);
    }

    /**
     * PUT /team/seats  — a NON-resetting limit driven by an absolute count.
     *
     * Seats go up AND down, but useFeature only ever increments. For a "set the
     * current value" counter, use reportStorage() (absolute report) guarded by
     * check() so you never set it past the cap.
     */
    public function setSeatCount(Request $request): JsonResponse
    {
        $user      = $request->user();
        $seatCount = (float) $request->integer('seats');
        $sub       = $user->subscription();

        // check() validates the absolute target against the cap.
        if (! Tashil::usage()->check($sub, 'team-seats', $seatCount)) {
            return response()->json([
                'message' => 'Seat count exceeds your plan limit.',
                'limit'   => $user->featureUsage('team-seats') + ($user->featureRemaining('team-seats') ?? 0),
            ], 422);
        }

        // Absolute set (not an increment). Writes the counter + a 'report' log.
        $user->reportStorage('team-seats', $seatCount);

        return response()->json([
            'seats'     => $user->featureUsage('team-seats'),
            'remaining' => $user->featureRemaining('team-seats'),
        ]);
    }
}

/*
|------------------------------------------------------------------------------
| Resetting & warnings
|------------------------------------------------------------------------------
|
| Auto reset: 'api-calls' is resetMonthly(); the scheduled tashil:reset-quotas
| command zeroes counters whose period_end has elapsed, anchored to the PREVIOUS
| period_end (never now()) so a late cron doesn't drift the schedule.
|
| Manual reset (e.g. an admin "reset quota" button):
|     Tashil::usage()->resetUsage($subscription, 'api-calls');
|
| 80% warning — listen for it and email the customer once per period:
|     Event::listen(UsageLimitWarning::class, function (UsageLimitWarning $e) {
|         // $e->subscription, $e->feature, $e->usage, $e->limit
|     });
|
| Route/Blade gating uses the SAME feature: / @feature checks (quota remaining):
|     Route::middleware('feature:api-calls')->post('/api/call', ...);
*/
