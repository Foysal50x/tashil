<?php

use Foysal50x\Tashil\Models\SubscriptionEvent;
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

/**
 * Count event-store rows of a given type for a subscription. The event log
 * is written synchronously inside the service transaction, so it is the most
 * reliable signal that a transition actually fired.
 */
function subscriptionEventCount(int $subscriptionId, string $eventType): int
{
    return SubscriptionEvent::query()
        ->where('subscription_id', $subscriptionId)
        ->where('event_type', $eventType)
        ->count();
}
