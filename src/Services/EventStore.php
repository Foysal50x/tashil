<?php

namespace Foysal50x\Tashil\Services;

use Foysal50x\Tashil\Contracts\SubscriptionEventRepositoryInterface;
use Foysal50x\Tashil\Managers\DatabaseManager;
use Foysal50x\Tashil\Models\Subscription;
use Foysal50x\Tashil\Models\SubscriptionEvent;
use Illuminate\Support\Str;

/**
 * Append-only event store for Subscription state transitions.
 *
 * Serializes appends per subscription via a SELECT … FOR UPDATE on the
 * subscriptions row, guaranteeing strictly increasing per-subscription
 * sequence_num even under concurrent writers.
 *
 * Idempotency: callers may pass an idempotency_key (UUID or arbitrary
 * string). If an event with the same (subscription_id, idempotency_key)
 * already exists, the existing row is returned and no new event is
 * appended.
 */
class EventStore
{
    public function __construct(
        protected DatabaseManager $db,
        protected SubscriptionEventRepositoryInterface $events,
    ) {}

    public function append(
        Subscription $subscription,
        string $eventType,
        array $payload = [],
        ?string $idempotencyKey = null,
        array $metadata = [],
        ?\DateTimeInterface $occurredAt = null,
    ): SubscriptionEvent {
        $connection = $this->db->connection();

        return $connection->transaction(function () use ($connection, $subscription, $eventType, $payload, $idempotencyKey, $metadata, $occurredAt) {
            // Lock the subscription row so concurrent appenders serialize
            // and assign monotonically increasing sequence_num values.
            $locked = $connection->table($subscription->getTable())
                ->where('id', $subscription->id)
                ->lockForUpdate()
                ->first(['id', 'last_event_seq']);

            // Idempotency check runs under the lock so concurrent appenders
            // with the same key observe the existing row instead of racing
            // past the pre-check and tripping the unique constraint.
            if ($idempotencyKey !== null) {
                $existing = $this->events->findByIdempotencyKey($subscription->id, $idempotencyKey);
                if ($existing) {
                    return $existing;
                }
            }

            $nextSeq = ((int) ($locked->last_event_seq ?? 0)) + 1;

            $now = now();

            $event = $this->events->insert([
                'event_id'        => (string) Str::uuid(),
                'subscription_id' => $subscription->id,
                'event_type'      => $eventType,
                'sequence_num'    => $nextSeq,
                'payload'         => $payload,
                'metadata'        => $metadata,
                'idempotency_key' => $idempotencyKey,
                'occurred_at'     => $occurredAt ?? $now,
                'recorded_at'     => $now,
            ]);

            $connection->table($subscription->getTable())
                ->where('id', $subscription->id)
                ->update(['last_event_seq' => $nextSeq]);

            $subscription->last_event_seq = $nextSeq;

            return $event;
        });
    }
}
