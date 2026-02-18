<?php

use Foysal50x\Tashil\Support\Query\DateFmt;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| DateFmt Expression Tests
|--------------------------------------------------------------------------
|
| DateFmt generates driver-specific SQL via getValue(Grammar). We test by
| embedding the expression in a real query builder (SQLite in tests) and
| inspecting the generated SQL.
|
*/

it('generates strftime SQL for Y-m format on SQLite', function () {
    $expr = new DateFmt('created_at', 'Y-m');
    $sql = DB::table('sqlite_master')->select($expr)->toRawSql();

    expect($sql)->toContain("strftime('%Y-%m'");
});

it('generates strftime SQL for Y-m-d format on SQLite', function () {
    $expr = new DateFmt('created_at', 'Y-m-d');
    $sql = DB::table('sqlite_master')->select($expr)->toRawSql();

    expect($sql)->toContain("strftime('%Y-%m-%d'");
});

it('generates strftime SQL for Y format on SQLite', function () {
    $expr = new DateFmt('created_at', 'Y');
    $sql = DB::table('sqlite_master')->select($expr)->toRawSql();

    expect($sql)->toContain("strftime('%Y'");
});

it('generates strftime SQL for m format on SQLite', function () {
    $expr = new DateFmt('created_at', 'm');
    $sql = DB::table('sqlite_master')->select($expr)->toRawSql();

    expect($sql)->toContain("strftime('%m'");
});

it('generates strftime SQL for d format on SQLite', function () {
    $expr = new DateFmt('created_at', 'd');
    $sql = DB::table('sqlite_master')->select($expr)->toRawSql();

    expect($sql)->toContain("strftime('%d'");
});

it('defaults to Y-m-d format for unknown format on SQLite', function () {
    $expr = new DateFmt('created_at', 'custom-unknown');
    $sql = DB::table('sqlite_master')->select($expr)->toRawSql();

    expect($sql)->toContain("strftime('%Y-%m-%d'");
});

it('uses default Y-m format when none specified', function () {
    $expr = new DateFmt('paid_at');
    $sql = DB::table('sqlite_master')->select($expr)->toRawSql();

    expect($sql)->toContain("strftime('%Y-%m'")
        ->toContain('paid_at');
});

it('implements Expression interface', function () {
    $expr = new DateFmt('created_at');

    expect($expr)->toBeInstanceOf(\Illuminate\Contracts\Database\Query\Expression::class);
});
