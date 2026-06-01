<?php

namespace Foysal50x\Tashil\Http\Middleware;

use Closure;
use Foysal50x\Tashil\Tashil;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Aborts with 403 unless the resolved Subscribable's current subscription
 * grants access to the named feature. Honors per-type semantics:
 *   - Boolean: value must be truthy.
 *   - Limit:   has remaining quota (>0) or is unlimited.
 *   - Consumable / Enum: snapshot exists and feature is_active.
 *   - Metered: subscriber has at least 1 unit of balance.
 *
 *   Route::middleware('feature:api-calls')->group(fn () => /* ... *\/);
 */
class EnsureFeature
{
    public function handle(Request $request, Closure $next, string $slug): Response
    {
        $subscriber = app(Tashil::class)->resolveSubscribable();

        $subscription = $subscriber?->resolveSubscription();

        if ($subscriber === null
            || $subscription === null
            || ! $subscription->isValid()
            || ! app('tashil')->usage()->check($subscription, $slug)
        ) {
            abort(403, sprintf("The '%s' feature is required to access this resource.", $slug));
        }

        return $next($request);
    }
}
