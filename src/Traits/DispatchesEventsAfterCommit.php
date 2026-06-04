<?php

namespace Foysal50x\Tashil\Traits;

use Closure;
use Foysal50x\Tashil\Managers\DatabaseManager;
use Illuminate\Support\Facades\Config;

/**
 * Defers domain-event dispatch until the package's DB transaction commits.
 *
 * Shared by every service/observer that emits events from inside a Tashil
 * transaction. When `tashil.events.async` is true (default), the event fires
 * only after the package's connection commits, so listeners never observe torn
 * state from a half-written transaction. The connection is resolved through
 * DatabaseManager — the single source of connection truth — so deferral binds
 * to the same connection the work was written on, even when the host runs
 * Tashil on a non-default connection. When async is off, dispatch inline.
 */
trait DispatchesEventsAfterCommit
{
    protected function dispatchAfterCommit(Closure $dispatcher): void
    {
        if (! Config::get('tashil.events.async', true)) {
            $dispatcher();

            return;
        }

        app(DatabaseManager::class)->connection()->afterCommit($dispatcher);
    }
}
