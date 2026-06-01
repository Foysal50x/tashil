<?php

namespace Foysal50x\Tashil\Http\Middleware;

use Closure;
use Foysal50x\Tashil\Tashil;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Aborts with 403 unless the resolved Subscribable holds a currently
 * valid subscription. Active, OnTrial, and PendingCancellation (still
 * in the grace window) all pass.
 *
 *   Route::middleware('subscribed')->group(fn () => /* ... *\/);
 */
class EnsureSubscribed
{
    public function handle(Request $request, Closure $next): Response
    {
        $subscriber = app(Tashil::class)->resolveSubscribable();

        if ($subscriber === null || ! $subscriber->resolveSubscription()?->isValid()) {
            abort(403, 'A valid subscription is required to access this resource.');
        }

        return $next($request);
    }
}
