<?php

namespace Foysal50x\Tashil\Managers;

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Config;

class DatabaseManager
{
    public function connection(): Connection
    {
        $connectionName = Config::get('tashil.database.connection');
        
        return DB::connection($connectionName);
    }

    public function getTable(string $key): string
    {
        $prefix = Config::get('tashil.database.prefix', 'tashil_');
        $table = Config::get("tashil.database.tables.{$key}", $key);

        return $prefix . $table;
    }

    public function query(string $tableKey)
    {
        return $this->connection()->table($this->getTable($tableKey));
    }
}
