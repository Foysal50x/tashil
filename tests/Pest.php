<?php

use Foysal50x\Tashil\Tests\Fixtures\User;
use Foysal50x\Tashil\Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

uses(TestCase::class)->in('Unit', 'Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

/**
 * Create a test User model for subscription testing.
 */
function createUser(array $attributes = []): User
{
    return User::create(array_merge([
        'name'  => 'Test User',
        'email' => 'test-' . uniqid() . '@example.com',
    ], $attributes));
}
