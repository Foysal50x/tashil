<?php

declare(strict_types=1);

namespace Foysal50x\Tashil\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

abstract class BaseModel extends Model
{
    public function getConnectionName(): ?string
    {
        return Config::get('tashil.database.connection');
    }

    public function getTable(): string
    {
        // Resolve table name from config based on a simple convention:
        // The model class name is mapped to a config key in tashil.database.tables.
        // e.g. Package → 'packages', SubscriptionEvent → 'subscription_events'
        $baseName = class_basename(static::class);
        $snakeKey = Str::snake(Str::pluralStudly($baseName));

        $configKey = "tashil.database.tables.{$snakeKey}";
        $configured = Config::get($configKey);

        $prefix = Config::get('tashil.database.prefix', 'tashil_');

        if ($configured) {
            return $prefix . $configured;
        }

        return $prefix . parent::getTable();
    }
}
