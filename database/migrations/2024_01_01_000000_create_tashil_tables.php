<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Config;

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

        $this->schema()->create($prefix . ($tables['packages'] ?? 'packages'), function (Blueprint $table) use ($prefix, $tables) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2)->default(0.00);
            $table->decimal('original_price', 10, 2)->nullable();
            $table->string('currency', 3)->default(config('tashil.currency', 'USD'));
            $table->string('billing_period')->default('month'); // day, week, month, year
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

        $this->schema()->create($prefix . ($tables['features'] ?? 'features'), function (Blueprint $table) use ($prefix, $tables) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('type')->default('boolean'); // boolean, limit, consumable
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('sort_order');
        });

        $this->schema()->create($prefix . ($tables['package_feature'] ?? 'package_feature'), function (Blueprint $table) use ($prefix, $tables) {
            $table->id();
            $table->foreignId('package_id')
                ->constrained($prefix . ($tables['packages'] ?? 'packages'))
                ->cascadeOnDelete();
            $table->foreignId('feature_id')
                ->constrained($prefix . ($tables['features'] ?? 'features'))
                ->cascadeOnDelete();
            $table->string('value')->nullable(); // limit amount or config value
            $table->boolean('is_available')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['package_id', 'feature_id']);
        });

        $this->schema()->create($prefix . ($tables['subscriptions'] ?? 'subscriptions'), function (Blueprint $table) use ($prefix, $tables) {
            $table->id();
            $table->morphs('subscriber');
            $table->foreignId('package_id')
                ->constrained($prefix . ($tables['packages'] ?? 'packages'));
            $table->string('status')->default('pending'); // active, cancelled, expired, etc.
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->boolean('auto_renew')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['subscriber_type', 'subscriber_id', 'status']);
            $table->index(['status', 'ends_at']);
            $table->index(['status', 'auto_renew', 'ends_at']);
        });

        $this->schema()->create($prefix . ($tables['subscription_items'] ?? 'subscription_items'), function (Blueprint $table) use ($prefix, $tables) {
            $table->id();
            $table->foreignId('subscription_id')->constrained($prefix . ($tables['subscriptions'] ?? 'subscriptions'))->cascadeOnDelete();
            $table->foreignId('feature_id')->constrained($prefix . ($tables['features'] ?? 'features'))->cascadeOnDelete();
            $table->string('value')->nullable();
            $table->decimal('usage', 12, 4)->default(0);
            $table->timestamps();

            $table->unique(['subscription_id', 'feature_id']);
        });

        $this->schema()->create($prefix . ($tables['usage_logs'] ?? 'usage_logs'), function (Blueprint $table) use ($prefix, $tables) {
            $table->id();
            $table->foreignId('subscription_id')->constrained($prefix . ($tables['subscriptions'] ?? 'subscriptions'))->cascadeOnDelete();
            $table->foreignId('feature_id')->constrained($prefix . ($tables['features'] ?? 'features'))->cascadeOnDelete();
            $table->decimal('amount', 12, 4);
            $table->string('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        $this->schema()->create($prefix . ($tables['invoices'] ?? 'invoices'), function (Blueprint $table) use ($prefix, $tables) {
            $table->id();
            $table->foreignId('subscription_id')->constrained($prefix . ($tables['subscriptions'] ?? 'subscriptions'))->cascadeOnDelete();
            $table->string('invoice_number')->unique();
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default(config('tashil.currency', 'USD'));
            $table->string('status')->default('pending'); // pending, paid, void, refunded
            $table->dateTime('issued_at');
            $table->dateTime('due_date')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        $this->schema()->create($prefix . ($tables['transactions'] ?? 'transactions'), function (Blueprint $table) use ($prefix, $tables) {
            $table->id();
            $table->foreignId('invoice_id')->constrained($prefix . ($tables['invoices'] ?? 'invoices'))->cascadeOnDelete();
            $table->string('gateway')->default('manual');
            $table->string('transaction_id')->nullable();
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3);
            $table->string('status')->default('pending'); // pending, success, failed
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
        $this->schema()->dropIfExists($prefix . ($tables['usage_logs'] ?? 'usage_logs'));
        $this->schema()->dropIfExists($prefix . ($tables['subscription_items'] ?? 'subscription_items'));
        $this->schema()->dropIfExists($prefix . ($tables['subscriptions'] ?? 'subscriptions'));
        $this->schema()->dropIfExists($prefix . ($tables['package_feature'] ?? 'package_feature'));
        $this->schema()->dropIfExists($prefix . ($tables['features'] ?? 'features'));
        $this->schema()->dropIfExists($prefix . ($tables['packages'] ?? 'packages'));
    }
};
