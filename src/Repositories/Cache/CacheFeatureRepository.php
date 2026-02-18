<?php

namespace Foysal50x\Tashil\Repositories\Cache;

use Foysal50x\Tashil\Contracts\FeatureRepositoryInterface;
use Foysal50x\Tashil\Managers\CacheManager;
use Foysal50x\Tashil\Models\Feature;

/**
 * @property FeatureRepositoryInterface $repository
 */
class CacheFeatureRepository extends BaseCacheRepository implements FeatureRepositoryInterface
{
    public function __construct(
        FeatureRepositoryInterface $repository,
        CacheManager $cacheManager,
        int $cacheTtl,
        string $cachePrefix,
    ) {
        parent::__construct($repository, $cacheManager, $cacheTtl, $cachePrefix);
    }

    public function create(array $data): Feature
    {
        $result = $this->repository->create($data);
        $this->forget("feature:{$result->id}");
        $this->forget("feature:{$result->slug}");
        $this->forget("features:all");

        return $result;
    }

    public function updateOrCreate(array $attributes, array $values): Feature
    {
        $result = $this->repository->updateOrCreate($attributes, $values);

        return $result;
    }
}
