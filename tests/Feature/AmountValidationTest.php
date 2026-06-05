<?php

declare(strict_types=1);

use Foysal50x\Tashil\Models\Feature;
use Foysal50x\Tashil\Models\FeatureUsage;
use Foysal50x\Tashil\Models\Package;
use Foysal50x\Tashil\Models\UsageLog;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__ . '/../Fixtures/create_users_table.php');

    $this->limit = Feature::create(['name' => 'API', 'slug' => 'api', 'type' => 'limit']);
    $this->storage = Feature::create(['name' => 'Storage', 'slug' => 'storage', 'type' => 'consumable']);

    $this->package = Package::create([
        'name'             => 'Pro',
        'slug'             => 'pro',
        'price'            => 0,
        'billing_period'   => 'month',
        'billing_interval' => 1,
    ]);
    $this->package->features()->attach($this->limit, ['value' => '100']);
    $this->package->features()->attach($this->storage, ['value' => null]);

    $this->user = createUser();
    $this->subscription = app('tashil')->subscription()->subscribe($this->user, $this->package);
});

it('increment rejects negative amount without touching the counter', function () {
    $before = (float) FeatureUsage::where('subscription_id', $this->subscription->id)
        ->whereHas('feature', fn ($q) => $q->where('slug', 'api'))->value('usage');

    $result = app('tashil')->usage()->increment($this->subscription, 'api', -10);

    expect($result)->toBeFalse();
    expect((float) FeatureUsage::where('subscription_id', $this->subscription->id)
        ->whereHas('feature', fn ($q) => $q->where('slug', 'api'))->value('usage'))
        ->toBe($before);
    expect(UsageLog::count())->toBe(0);
});

it('increment rejects zero amount', function () {
    expect(app('tashil')->usage()->increment($this->subscription, 'api', 0))->toBeFalse();
    expect(UsageLog::count())->toBe(0);
});

it('check rejects negative or zero amount', function () {
    // 100-unit quota would falsely report "OK" for a -1 increment
    // (usage + -1 <= 100). The guard short-circuits that lie.
    expect(app('tashil')->usage()->check($this->subscription, 'api', -1))->toBeFalse();
    expect(app('tashil')->usage()->check($this->subscription, 'api', 0))->toBeFalse();
    expect(app('tashil')->usage()->check($this->subscription, 'api', 1))->toBeTrue();
});

it('reportStorage rejects negative amount but allows zero', function () {
    expect(app('tashil')->usage()->reportStorage($this->subscription, 'storage', -5))->toBeFalse();
    expect(UsageLog::count())->toBe(0);

    expect(app('tashil')->usage()->reportStorage($this->subscription, 'storage', 0))->toBeTrue();
    expect(UsageLog::count())->toBe(1);

    expect(app('tashil')->usage()->reportStorage($this->subscription, 'storage', 12.5))->toBeTrue();
});

it('useFeature trait method rejects negative amount', function () {
    expect($this->user->useFeature('api', -1))->toBeFalse();
    expect($this->user->useFeature('api', 0))->toBeFalse();
});
