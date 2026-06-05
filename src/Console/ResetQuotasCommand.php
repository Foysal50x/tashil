<?php

declare(strict_types=1);

namespace Foysal50x\Tashil\Console;

use Carbon\Carbon;
use Foysal50x\Tashil\Services\Resetter;
use Illuminate\Console\Command;

class ResetQuotasCommand extends Command
{
    protected $signature = 'tashil:reset-quotas {--date= : Process for a specific date (Y-m-d H:i:s)}';

    protected $description = 'Reset feature_usages whose period has elapsed';

    public function handle(Resetter $resetter): int
    {
        $now = $this->option('date') ? Carbon::parse($this->option('date')) : Carbon::now();

        $count = $resetter->resetDueQuotas($now);

        $this->info("Reset {$count} quota(s) for {$now->toDateTimeString()}");

        return Command::SUCCESS;
    }
}
