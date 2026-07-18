<?php

declare(strict_types=1);

namespace Foysal50x\Tashil\Support\Cache;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Converts repository results into serializer-safe payloads (scalars and
 * arrays only) before they reach the cache store, and rebuilds them on read.
 *
 * Hosts may run their cache store with `cache.serializable_classes = false`,
 * which makes Laravel unserialize with `['allowed_classes' => false]` — any
 * cached object then comes back as __PHP_Incomplete_Class and fatals on
 * first access. Dehydrating to plain arrays keeps the cache layer working
 * under any serializer policy.
 */
final class ModelCacheCodec
{
    private const MODEL = '__tashil_model';

    private const COLLECTION = '__tashil_collection';

    private const DATETIME = '__tashil_datetime';

    private const ENUM = '__tashil_enum';

    public static function dehydrate(mixed $value): mixed
    {
        if ($value instanceof Model) {
            return [
                self::MODEL  => $value::class,
                'connection' => $value->getConnectionName(),
                'attributes' => array_map([self::class, 'dehydrate'], $value->getAttributes()),
                'relations'  => array_map([self::class, 'dehydrate'], $value->getRelations()),
            ];
        }

        if ($value instanceof Collection) {
            return [
                self::COLLECTION => $value::class,
                'items'          => $value->map(fn ($item) => self::dehydrate($item))->all(),
            ];
        }

        if ($value instanceof DateTimeInterface) {
            return [self::DATETIME => $value->format(DateTimeInterface::RFC3339_EXTENDED)];
        }

        if ($value instanceof BackedEnum) {
            return [self::ENUM => $value::class, 'value' => $value->value];
        }

        if (is_array($value)) {
            return array_map([self::class, 'dehydrate'], $value);
        }

        return $value;
    }

    public static function hydrate(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (isset($value[self::MODEL])) {
            /** @var Model $prototype */
            $prototype = new $value[self::MODEL];
            $model = $prototype->newFromBuilder(
                array_map([self::class, 'hydrate'], $value['attributes']),
                $value['connection'],
            );

            foreach ($value['relations'] as $name => $relation) {
                $model->setRelation($name, self::hydrate($relation));
            }

            return $model;
        }

        if (isset($value[self::COLLECTION])) {
            return new $value[self::COLLECTION](
                array_map([self::class, 'hydrate'], $value['items']),
            );
        }

        if (isset($value[self::DATETIME])) {
            return Carbon::parse($value[self::DATETIME]);
        }

        if (isset($value[self::ENUM])) {
            return $value[self::ENUM]::from($value['value']);
        }

        return array_map([self::class, 'hydrate'], $value);
    }
}
