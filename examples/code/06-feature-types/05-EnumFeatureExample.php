<?php

declare(strict_types=1);

namespace App\Http\Controllers\Features;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * FEATURE TYPE 5 of 5 — ENUM  (a one-of-a-set gate)
 * =================================================
 *
 * Catalog (CatalogSeeder):
 *     Tashil::feature('export-format')->type(FeatureType::Enum)->create();
 *     // free       → ->feature($exportFormat, value: 'csv')
 *     // pro        → ->feature($exportFormat, value: 'pdf')
 *     // enterprise → ->feature($exportFormat, value: 'json')
 *
 * Semantics:
 *   - Like Boolean, it's a gate with no counter — but instead of true/false the
 *     snapshot value is the CHOSEN OPTION for that plan (the tier unlocked).
 *   - hasFeature('export-format') is true whenever the feature is on the plan
 *     (existence), so the meaningful read is featureValue() — WHICH option.
 *   - useFeature() returns false (nothing to consume), same as Boolean.
 *
 * Use it for: tiered options — export format, support tier (basic/priority/
 * dedicated), region (us/eu/global), SLA level, log-retention bracket.
 */
class EnumFeatureExample extends Controller
{
    /**
     * Your app defines what the options mean and how they rank. Tashil just
     * stores the chosen string per plan.
     */
    private const FORMAT_RANK = ['csv' => 1, 'xlsx' => 2, 'pdf' => 3, 'json' => 4];

    /**
     * GET /exports/options — what this plan unlocks, and everything it implies.
     */
    public function options(Request $request): JsonResponse
    {
        $user = $request->user();

        $granted = (string) $user->featureValue('export-format');   // e.g. 'pdf'

        // Treat the chosen option as "this tier and everything below it".
        $unlocked = array_keys(array_filter(
            self::FORMAT_RANK,
            fn ($rank) => $rank <= (self::FORMAT_RANK[$granted] ?? 0),
        ));

        return response()->json([
            'plan_format'       => $granted,
            'available_formats' => $unlocked,    // ['csv', 'xlsx', 'pdf']
        ]);
    }

    /**
     * POST /exports  — gate the requested format against the plan's enum value.
     */
    public function export(Request $request): JsonResponse
    {
        $user = $request->user();
        $requested = (string) $request->input('format', 'csv');

        // 1. Is the feature on the plan at all?
        if (! $user->hasFeature('export-format')) {
            return response()->json(['message' => 'Exports are not available on your plan.'], 403);
        }

        // 2. Is the REQUESTED option within the plan's granted tier?
        $granted = (string) $user->featureValue('export-format');
        $grantedRank = self::FORMAT_RANK[$granted] ?? 0;
        $wantRank = self::FORMAT_RANK[$requested] ?? PHP_INT_MAX;

        if ($wantRank > $grantedRank) {
            return response()->json([
                'message'     => "Your plan unlocks '{$granted}'. Upgrade to export '{$requested}'.",
                'plan_format' => $granted,
            ], 403);
        }

        // ... generate the export in $requested format ...

        return response()->json(['ok' => true, 'format' => $requested]);
    }
}

/*
|------------------------------------------------------------------------------
| Middleware / Blade
|------------------------------------------------------------------------------
|
| The feature: middleware and @feature directive check EXISTENCE only (the enum
| is present on the plan) — they can't compare against a specific option, since
| the requested value isn't known to the gate. So:
|
|   - Use middleware/@feature for the coarse "exports unlocked?" gate:
|         Route::middleware('feature:export-format')->post('/exports', ...);
|
|         @feature('export-format')
|             <x-export-menu :format="auth()->user()->featureValue('export-format')" />
|         @endfeature
|
|   - Compare the requested option to featureValue() in your controller (as
|     above) for the fine-grained "which option?" decision.
|
| Like Boolean, useFeature('export-format') returns false — there is no counter.
*/
