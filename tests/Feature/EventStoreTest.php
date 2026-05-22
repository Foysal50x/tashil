<?php

use Foysal50x\Tashil\Models\Feature;
use Foysal50x\Tashil\Models\Package;
use Foysal50x\Tashil\Models\SubscriptionEvent;
use Foysal50x\Tashil\Services\EventStore;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__ . '/../Fixtures/create_users_table.php');

    $feature = Feature::create([
        'name' => 'API',
        'slug' => 'api',
        'type' => 'limit',
    ]);

    $this->package = Package::create([
        'name'             => 'Pro',
        'slug'             => 'pro',
        'price'            => 10.00,
        'billing_period'   => 'month',
        'billing_interval' => 1,
    ]);
    $this->package->features()->attach($feature, ['value' => '100']);

    $this->user = createUser();
    $this->subscription = app('tashil')->subscription()->subscribe($this->user, $this->package);
});

it('assigns monotonically increasing sequence_num per subscription', function () {
    /** @var EventStore $store */
    $store = app(EventStore::class);

    // 'subscription.created' already appended at sequence 1 by subscribe().
    $store->append($this->subscription, 'custom.first');
    $store->append($this->subscription, 'custom.second');
    $store->append($this->subscription, 'custom.third');

    $events = SubscriptionEvent::where('subscription_id', $this->subscription->id)
        ->orderBy('sequence_num')
        ->get();

    expect($events->pluck('sequence_num')->all())->toBe([1, 2, 3, 4]);
    expect($events->pluck('event_type')->all())->toBe([
        'subscription.created',
        'custom.first',
        'custom.second',
        'custom.third',
    ]);

    $this->subscription->refresh();
    expect((int) $this->subscription->last_event_seq)->toBe(4);
});

it('is idempotent on identical idempotency_key', function () {
    /** @var EventStore $store */
    $store = app(EventStore::class);

    $first = $store->append($this->subscription, 'trial.ending', ['days_remaining' => 3], idempotencyKey: 'key-1');
    $second = $store->append($this->subscription, 'trial.ending', ['days_remaining' => 3], idempotencyKey: 'key-1');

    expect($second->id)->toBe($first->id);
    expect(SubscriptionEvent::where('event_type', 'trial.ending')->count())->toBe(1);
});

it('rejects updates and deletes on event rows', function () {
    /** @var EventStore $store */
    $store = app(EventStore::class);

    $event = $store->append($this->subscription, 'custom.kind');

    expect(fn () => $event->update(['event_type' => 'tampered']))
        ->toThrow(RuntimeException::class);

    expect(fn () => $event->delete())
        ->toThrow(RuntimeException::class);
});

it('replays history for a subscription in sequence order', function () {
    /** @var EventStore $store */
    $store = app(EventStore::class);

    $store->append($this->subscription, 'a');
    $store->append($this->subscription, 'b');
    $store->append($this->subscription, 'c');

    $types = $this->subscription->events()->get()->pluck('event_type')->all();

    expect($types)->toBe(['subscription.created', 'a', 'b', 'c']);
});
