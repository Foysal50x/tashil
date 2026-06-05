<?php

declare(strict_types=1);

namespace Foysal50x\Tashil\Http\Middleware;

use Closure;
use Foysal50x\Tashil\Tashil;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Aborts with 403 unless the resolved Subscribable holds a currently
 * valid subscription on the named package slug.
 *
 *   Route::middleware('plan:pro')->group(fn () => /* ... *\/);
 */
class EnsurePlan
{
    public function handle(Request $request, Closure $next, string $slug): Response
    {
        $subscriber = app(Tashil::class)->resolveSubscribable();

        $subscription = $subscriber?->resolveSubscription();

        if ($subscriber === null
            || $subscription === null
            || ! $subscription->isValid()
            || $subscription->package?->slug !== $slug
        ) {
            abort(403, sprintf("The '%s' plan is required to access this resource.", $slug));
        }

        return $next($request);
    }
}
