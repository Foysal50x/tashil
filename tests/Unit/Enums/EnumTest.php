<?php

use Foysal50x\Tashil\Enums\FeatureType;
use Foysal50x\Tashil\Enums\InvoiceStatus;
use Foysal50x\Tashil\Enums\Period;
use Foysal50x\Tashil\Enums\SubscriptionStatus;
use Foysal50x\Tashil\Enums\TransactionStatus;

// ── FeatureType ─────────────────────────────────────────────────

it('has correct FeatureType cases', function () {
    expect(FeatureType::cases())->toHaveCount(4);
    expect(FeatureType::Boolean->value)->toBe('boolean');
    expect(FeatureType::Limit->value)->toBe('limit');
    expect(FeatureType::Consumable->value)->toBe('consumable');
    expect(FeatureType::Enum->value)->toBe('enum');
});

it('can create FeatureType from value', function () {
    expect(FeatureType::from('boolean'))->toBe(FeatureType::Boolean);
    expect(FeatureType::from('limit'))->toBe(FeatureType::Limit);
    expect(FeatureType::from('consumable'))->toBe(FeatureType::Consumable);
});

// ── Period ───────────────────────────────────────────────────────

it('has correct Period cases', function () {
    expect(Period::cases())->toHaveCount(5);
    expect(Period::Day->value)->toBe('day');
    expect(Period::Week->value)->toBe('week');
    expect(Period::Month->value)->toBe('month');
    expect(Period::Year->value)->toBe('year');
    expect(Period::Lifetime->value)->toBe('lifetime');
});

// ── SubscriptionStatus ──────────────────────────────────────────

it('has correct SubscriptionStatus cases', function () {
    expect(SubscriptionStatus::cases())->toHaveCount(7);
    expect(SubscriptionStatus::Pending->value)->toBe('pending');
    expect(SubscriptionStatus::Active->value)->toBe('active');
    expect(SubscriptionStatus::OnTrial->value)->toBe('on_trial');
    expect(SubscriptionStatus::PastDue->value)->toBe('past_due');
    expect(SubscriptionStatus::Cancelled->value)->toBe('cancelled');
    expect(SubscriptionStatus::Expired->value)->toBe('expired');
    expect(SubscriptionStatus::Suspended->value)->toBe('suspended');
});

// ── InvoiceStatus ───────────────────────────────────────────────

it('has correct InvoiceStatus cases', function () {
    expect(InvoiceStatus::cases())->toHaveCount(5);
    expect(InvoiceStatus::Draft->value)->toBe('draft');
    expect(InvoiceStatus::Pending->value)->toBe('pending');
    expect(InvoiceStatus::Paid->value)->toBe('paid');
    expect(InvoiceStatus::Void->value)->toBe('void');
    expect(InvoiceStatus::Refunded->value)->toBe('refunded');
});

// ── TransactionStatus ───────────────────────────────────────────

it('has correct TransactionStatus cases', function () {
    expect(TransactionStatus::cases())->toHaveCount(4);
    expect(TransactionStatus::Pending->value)->toBe('pending');
    expect(TransactionStatus::Success->value)->toBe('success');
    expect(TransactionStatus::Failed->value)->toBe('failed');
    expect(TransactionStatus::Refunded->value)->toBe('refunded');
});
