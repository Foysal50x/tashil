<?php

namespace Foysal50x\Tashil\Repositories;

use Foysal50x\Tashil\Contracts\PackageRepositoryInterface;
use Foysal50x\Tashil\Models\Package;

class EloquentPackageRepository implements PackageRepositoryInterface
{
    public function create(array $data): Package
    {
        return Package::create($data);
    }

    public function updateOrCreate(array $attributes, array $values): Package
    {
        return Package::updateOrCreate($attributes, $values);
    }

    public function syncFeatures(Package $package, array $syncData): void
    {
        $package->features()->syncWithoutDetaching($syncData);
    }
}
