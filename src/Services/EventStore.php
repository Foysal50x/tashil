<?php

declare(strict_types=1);

namespace Foysal50x\Tashil\Services;

use Foysal50x\Tashil\Contracts\Subscribable;
use Foysal50x\Tashil\Contracts\SubscriptionEventRepositoryInterface;
use Foysal50x\Tashil\Managers\DatabaseManager;
use Foysal50x\Tashil\Models\Package;
use Foysal50x\Tashil\Models\Subscription;
use Foysal50x\Tashil\Models\SubscriptionEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Pagination\LengthAwarePaginator;
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
    /**
     * UNIQUE(subscription_id, sequence_num) collisions are normally
     * impossible — the lockForUpdate serializes appenders. They only
     * happen when last_event_seq desynced from the actual max seq (e.g.,
     * a raw SQL write that inserted an event without bumping
     * last_event_seq). We retry a small number of times, recomputing the
     * next seq from the table's max on each attempt, before giving up.
     */
    public const MAX_SEQUENCE_RETRIES = 3;

    public function __construct(
        protected DatabaseManager $db,
        protected SubscriptionEventRepositoryInterface $events,
    ) {}

    /**
     * Paginate the immutable history for a single subscription, newest first.
     * The log stays append-only — this is a read.
     *
     * @param  list<string>  $with  relations to eager-load (e.g. 'subscription')
     * @return LengthAwarePaginator<SubscriptionEvent>
     */
    public function historyFor(Subscription $subscription, int $perPage = 20, array $with = [], string $pageName = 'page'): LengthAwarePaginator
    {
        return $this->events->paginateForSubscription($subscription->id, $perPage, $with, $pageName);
    }

    /**
     * Paginate the combined history across every subscription the subscriber
     * has ever held (current and terminated), newest first.
     *
     * @param  list<string>  $with  relations to eager-load
     * @return LengthAwarePaginator<SubscriptionEvent>
     */
    public function historyForSubscriber(Subscribable $subscriber, int $perPage = 20, array $with = [], string $pageName = 'page'): LengthAwarePaginator
    {
        return $this->events->paginateForSubscriber(
            $subscriber->getSubscriberType(),
            $subscriber->getSubscriberKey(),
            $perPage,
            $with,
            $pageName,
        );
    }

    /**
     * Paginate the combined history across every subscription on a plan,
     * newest first — the plan-wide lifecycle timeline.
     *
     * @param  list<string>  $with  relations to eager-load (e.g. 'subscription.subscriber')
     * @return LengthAwarePaginator<SubscriptionEvent>
     */
    public function historyForPackage(Package $package, int $perPage = 20, array $with = [], string $pageName = 'page'): LengthAwarePaginator
    {
        return $this->events->paginateForPackage($package->id, $perPage, $with, $pageName);
    }

    public function append(
        Subscription $subscription,
        string $eventType,
        array $payload = [],
        ?string $idempotencyKey = null,
        array $metadata = [],
        ?\DateTimeInterface $occurredAt = null,
    ): SubscriptionEvent {
        $attempt = 0;

        while (true) {
            try {
                return $this->appendOnce($subscription, $eventType, $payload, $idempotencyKey, $metadata, $occurredAt, $attempt);
            } catch (UniqueConstraintViolationException $e) {
                // Idempotency-key collision can happen if a concurrent
                // appender inserted between our findByIdempotencyKey
                // and our insert. Re-checking under the next attempt's
                // lock will observe the row and return early.
                if ($idempotencyKey !== null) {
                    $existing = $this->events->findByIdempotencyKey($subscription->id, $idempotencyKey);
                    if ($existing) {
                        return $existing;
                    }
                }

                if (++$attempt >= self::MAX_SEQUENCE_RETRIES) {
                    throw $e;
                }
            }
        }
    }

    protected function appendOnce(
        Subscription $subscription,
        string $eventType,
        array $payload,
        ?string $idempotencyKey,
        array $metadata,
        ?\DateTimeInterface $occurredAt,
        int $attempt,
    ): SubscriptionEvent {
        $connection = $this->db->connection();

        return $connection->transaction(function () use ($connection, $subscription, $eventType, $payload, $idempotencyKey, $metadata, $occurredAt, $attempt) {
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

            // First attempt trusts the cached last_event_seq. Retries
            // recompute from the table's max so we recover when the
            // cached counter has desynced (e.g. an out-of-band insert).
            if ($attempt === 0) {
                $nextSeq = ((int) ($locked->last_event_seq ?? 0)) + 1;
            } else {
                $nextSeq = $this->events->maxSequence($subscription->id) + 1;
            }

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
