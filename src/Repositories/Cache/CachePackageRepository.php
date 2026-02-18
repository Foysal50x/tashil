<?php

namespace Foysal50x\Tashil\Repositories\Cache;

use Foysal50x\Tashil\Contracts\PackageRepositoryInterface;
use Foysal50x\Tashil\Managers\CacheManager;
use Foysal50x\Tashil\Models\Package;

/**
 * @property PackageRepositoryInterface $repository
 */
class CachePackageRepository extends BaseCacheRepository implements PackageRepositoryInterface
{
    public function __construct(
        PackageRepositoryInterface $repository,
        CacheManager $cacheManager,
        int $cacheTtl,
        string $cachePrefix,
    ) {
        parent::__construct($repository, $cacheManager, $cacheTtl, $cachePrefix);
    }

    public function create(array $data): Package
    {
        $result = $this->repository->create($data);
        $this->cacheManager->forget("package:slug:{$result->slug}");

        return $result;
    }

    public function updateOrCreate(array $attributes, array $values): Package
    {
        $result = $this->repository->updateOrCreate($attributes, $values);
        $this->forget("package:slug:{$result->slug}");

        return $result;
    }

    public function syncFeatures(Package $package, array $syncData): void
    {
        $this->repository->syncFeatures($package, $syncData);
        $this->forget('packages:all');
        $this->forget('packages:active');
        $this->forget("package:{$package->id}");
        $this->forget("package:{$package->slug}");
    }
}
