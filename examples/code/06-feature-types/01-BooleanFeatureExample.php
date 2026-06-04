<?php

declare(strict_types=1);

namespace App\Http\Controllers\Features;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * FEATURE TYPE 1 of 5 — BOOLEAN  (a pure on/off gate)
 * ===================================================
 *
 * Catalog (CatalogSeeder):
 *     Tashil::feature('sso')->name('Single Sign-On')->boolean()->create();
 *     // free plan       → ->feature($sso, value: 'false')   (locked)
 *     // pro/enterprise  → ->feature($sso, value: 'true')    (unlocked)
 *
 * Semantics:
 *   - No counter, never consumed. The package snapshot value ('true'/'false')
 *     is the whole story.
 *   - hasFeature('sso') reads that value's truthiness.
 *   - useFeature('sso') ALWAYS returns false — a boolean has no consume action.
 *
 * Use it for: SSO, white-labelling, priority support, "remove our branding".
 */
class BooleanFeatureExample extends Controller
{
    /**
     * GET /features/sso/status
     */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            // The gate check — true only when the plan attached value 'true'.
            'enabled' => $user->hasFeature('sso'),

            // The raw snapshot value if you need it ('true' / 'false').
            'raw_value' => $user->featureValue('sso'),
        ]);
    }

    /**
     * POST /sso/configure  — gate an action in a controller.
     */
    public function configureSso(Request $request): JsonResponse
    {
        $user = $request->user();

        // Enforce the gate before doing the privileged work.
        if (! $user->hasFeature('sso')) {
            return response()->json([
                'message' => 'SSO is not available on your plan. Upgrade to Pro.',
            ], 403);
        }

        // ... persist the customer's SAML/OIDC config ...

        return response()->json(['message' => 'SSO configured.']);
    }
}

/*
|------------------------------------------------------------------------------
| Gate it declaratively instead of in code
|------------------------------------------------------------------------------
|
| Route middleware (aborts 403 when the boolean is off):
|
|     Route::middleware('feature:sso')
|         ->post('/sso/configure', [BooleanFeatureExample::class, 'configureSso']);
|
| Blade — show UI only when unlocked:
|
|     @feature('sso')
|         <a href="{{ route('sso.configure') }}">Configure SSO</a>
|     @else
|         <x-upgrade-badge feature="Single Sign-On" />
|     @endfeature
|
| Note: useFeature('sso') returns false — there is nothing to "consume" on a
| boolean. Don't call it; use hasFeature() / @feature / feature: middleware.
*/
