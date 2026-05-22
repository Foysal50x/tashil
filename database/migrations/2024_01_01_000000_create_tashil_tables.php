<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection;

    public function __construct()
    {
        $this->connection = config('tashil.database.connection');
    }

    protected function schema()
    {
        return Schema::connection($this->connection);
    }

    public function up(): void
    {
        $prefix = config('tashil.database.prefix', 'tashil_');
        $tables = config('tashil.database.tables', []);

        $packagesTable = $prefix . ($tables['packages'] ?? 'packages');
        $featuresTable = $prefix . ($tables['features'] ?? 'features');
        $packageFeatureTable = $prefix . ($tables['package_feature'] ?? 'package_feature');
        $subscriptionsTable = $prefix . ($tables['subscriptions'] ?? 'subscriptions');
        $subFeaturesTable = $prefix . ($tables['subscription_features'] ?? 'subscription_features');
        $featureUsagesTable = $prefix . ($tables['feature_usages'] ?? 'feature_usages');
        $usageLogsTable = $prefix . ($tables['usage_logs'] ?? 'usage_logs');
        $subEventsTable = $prefix . ($tables['subscription_events'] ?? 'subscription_events');
        $invoicesTable = $prefix . ($tables['invoices'] ?? 'invoices');
        $transactionsTable = $prefix . ($tables['transactions'] ?? 'transactions');

        $this->schema()->create($packagesTable, function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2)->default(0.00);
            $table->decimal('original_price', 10, 2)->nullable();
            $table->string('currency', 3)->default(config('tashil.currency', 'USD'));
            $table->string('billing_period')->default('month');
            $table->integer('billing_interval')->default(1);
            $table->integer('trial_days')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->integer('sort_order')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'is_featured']);
            $table->index('sort_order');
        });

        $this->schema()->create($featuresTable, function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('type')->default('boolean');
            $table->string('reset_period')->default('never');
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('sort_order');
        });

        $this->schema()->create($packageFeatureTable, function (Blueprint $table) use ($packagesTable, $featuresTable) {
            $table->id();
            $table->foreignId('package_id')->constrained($packagesTable)->cascadeOnDelete();
            $table->foreignId('feature_id')->constrained($featuresTable)->cascadeOnDelete();
            $table->string('value')->nullable();
            $table->boolean('is_available')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['package_id', 'feature_id']);
        });

        $this->schema()->create($subscriptionsTable, function (Blueprint $table) use ($packagesTable) {
            $table->id();
            $table->morphs('subscriber');
            $table->foreignId('package_id')->constrained($packagesTable);

            // Scheduled-downgrade target (null when no change queued)
            $table->unsignedBigInteger('pending_package_id')->nullable();
            $table->timestamp('pending_change_at')->nullable();

            $table->string('status')->default('pending');

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            // Current billing period — advanced when an invoice is marked paid
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();

            // Trial lifecycle
            $table->timestamp('trial_started_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('trial_converted_at')->nullable();
            $table->timestamp('trial_expired_at')->nullable();

            // Cancellation
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('cancellation_effective_at')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->boolean('auto_renew')->default(true);

            // Event-store cursor (per-subscription monotonic sequence)
            $table->unsignedBigInteger('last_event_seq')->default(0);

            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['subscriber_type', 'subscriber_id', 'status']);
            $table->index(['status', 'current_period_end']);
            $table->index(['status', 'ends_at']);
            $table->index(['status', 'trial_ends_at']);
            $table->index(['status', 'auto_renew', 'current_period_end']);
            $table->index('pending_change_at');
        });

        $this->schema()->create($subFeaturesTable, function (Blueprint $table) use ($subscriptionsTable, $featuresTable) {
            $table->id();
            $table->foreignId('subscription_id')->constrained($subscriptionsTable)->cascadeOnDelete();
            $table->foreignId('feature_id')->constrained($featuresTable)->cascadeOnDelete();

            // Snapshot of feature metadata at the time of subscribe/switch.
            // Frozen for the lifetime of this snapshot row.
            $table->string('feature_slug');
            $table->string('feature_type');
            $table->string('value')->nullable();
            $table->string('reset_period');

            $table->timestamp('added_at');
            $table->timestamp('superseded_at')->nullable();

            $table->timestamps();

            $table->index(['subscription_id', 'superseded_at']);
            $table->index(['subscription_id', 'feature_id', 'superseded_at']);
        });

        $this->schema()->create($featureUsagesTable, function (Blueprint $table) use ($subscriptionsTable, $featuresTable) {
            $table->id();
            $table->foreignId('subscription_id')->constrained($subscriptionsTable)->cascadeOnDelete();
            $table->foreignId('feature_id')->constrained($featuresTable)->cascadeOnDelete();

            // Mutable counter. Every change is also written to usage_logs.
            $table->decimal('usage', 20, 4)->default(0);

            // Limit cached on the counter row for atomic UPDATE … WHERE usage+amount <= limit_value.
            // Null = unlimited. Mirrors the snapshot's value column for atomicity.
            $table->decimal('limit_value', 20, 4)->nullable();

            // Period window — when period_end <= now, the reset job zeroes usage and advances.
            $table->string('reset_period')->default('never');
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();

            $table->timestamps();

            $table->unique(['subscription_id', 'feature_id']);
            $table->index(['period_end', 'reset_period']);
        });

        $this->schema()->create($usageLogsTable, function (Blueprint $table) use ($subscriptionsTable, $featuresTable) {
            $table->id();
            $table->foreignId('subscription_id')->constrained($subscriptionsTable)->cascadeOnDelete();
            $table->foreignId('feature_id')->constrained($featuresTable)->cascadeOnDelete();
            $table->string('operation')->default('consume');
            $table->decimal('amount', 20, 4);
            $table->decimal('previous_usage', 20, 4)->nullable();
            $table->decimal('new_usage', 20, 4)->nullable();
            $table->string('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->index(['subscription_id', 'feature_id', 'created_at']);
        });

        $this->schema()->create($subEventsTable, function (Blueprint $table) use ($subscriptionsTable) {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->foreignId('subscription_id')->constrained($subscriptionsTable)->cascadeOnDelete();
            $table->string('event_type', 64);
            $table->unsignedBigInteger('sequence_num');
            $table->json('payload')->nullable();
            $table->json('metadata')->nullable();
            $table->uuid('idempotency_key')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('recorded_at');

            $table->unique(['subscription_id', 'sequence_num']);
            $table->unique(['subscription_id', 'idempotency_key']);
            $table->index(['subscription_id', 'event_type', 'occurred_at']);
        });

        $this->schema()->create($invoicesTable, function (Blueprint $table) use ($subscriptionsTable) {
            $table->id();
            $table->foreignId('subscription_id')->constrained($subscriptionsTable)->cascadeOnDelete();
            $table->string('invoice_number')->unique();
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default(config('tashil.currency', 'USD'));
            $table->string('status')->default('pending');
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->dateTime('issued_at');
            $table->dateTime('due_date')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['subscription_id', 'status']);
        });

        $this->schema()->create($transactionsTable, function (Blueprint $table) use ($invoicesTable) {
            $table->id();
            $table->foreignId('invoice_id')->constrained($invoicesTable)->cascadeOnDelete();
            $table->string('gateway')->default('manual');
            $table->string('transaction_id')->nullable();
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3);
            $table->string('status')->default('pending');
            $table->json('gateway_response')->nullable();
            $table->json('metadata')->nullable();
            $table->decimal('refunded_amount', 10, 2)->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->string('refund_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $prefix = config('tashil.database.prefix', 'tashil_');
        $tables = config('tashil.database.tables', []);

        $this->schema()->dropIfExists($prefix . ($tables['transactions'] ?? 'transactions'));
        $this->schema()->dropIfExists($prefix . ($tables['invoices'] ?? 'invoices'));
        $this->schema()->dropIfExists($prefix . ($tables['subscription_events'] ?? 'subscription_events'));
        $this->schema()->dropIfExists($prefix . ($tables['usage_logs'] ?? 'usage_logs'));
        $this->schema()->dropIfExists($prefix . ($tables['feature_usages'] ?? 'feature_usages'));
        $this->schema()->dropIfExists($prefix . ($tables['subscription_features'] ?? 'subscription_features'));
        $this->schema()->dropIfExists($prefix . ($tables['subscriptions'] ?? 'subscriptions'));
        $this->schema()->dropIfExists($prefix . ($tables['package_feature'] ?? 'package_feature'));
        $this->schema()->dropIfExists($prefix . ($tables['features'] ?? 'features'));
        $this->schema()->dropIfExists($prefix . ($tables['packages'] ?? 'packages'));
    }
};
