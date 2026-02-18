<?php

namespace Foysal50x\Tashil\Support\Query;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Grammar;

class DateFmt implements Expression
{
    public function __construct(
        private readonly string|Expression $column,
        private readonly string $format = 'Y-m'
    ) {}

    public function getValue(Grammar $grammar): string
    {
        $column = $grammar->wrap($this->column);
        $driver = $grammar->getConnection()->getDriverName();

        return match ($driver) {
            'sqlite' => "strftime('{$this->sqliteFormat()}', {$column})",
            'pgsql'  => "to_char({$column}, '{$this->postgresFormat()}')",
            'sqlsrv' => "format({$column}, '{$this->sqlsrvFormat()}')",
            default  => "date_format({$column}, '{$this->mysqlFormat()}')",
        };
    }

    private function sqliteFormat(): string
    {
        return match ($this->format) {
            'Y-m'   => '%Y-%m',
            'Y-m-d' => '%Y-%m-%d',
            'Y'     => '%Y',
            'm'     => '%m',
            'd'     => '%d',
            default => '%Y-%m-%d',
        };
    }

    private function postgresFormat(): string
    {
        return match ($this->format) {
            'Y-m'   => 'YYYY-MM',
            'Y-m-d' => 'YYYY-MM-DD',
            'Y'     => 'YYYY',
            'm'     => 'MM',
            'd'     => 'DD',
            default => 'YYYY-MM-DD',
        };
    }

    private function mysqlFormat(): string
    {
        return match ($this->format) {
            'Y-m'   => '%Y-%m',
            'Y-m-d' => '%Y-%m-%d',
            'Y'     => '%Y',
            'm'     => '%m',
            'd'     => '%d',
            default => '%Y-%m-%d',
        };
    }

    private function sqlsrvFormat(): string
    {
        return match ($this->format) {
            'Y-m'   => 'yyyy-MM',
            'Y-m-d' => 'yyyy-MM-dd',
            'Y'     => 'yyyy',
            'm'     => 'MM',
            'd'     => 'dd',
            default => 'yyyy-MM-dd',
        };
    }
}
