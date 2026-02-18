<?php

namespace Foysal50x\Tashil\Contracts;

use Foysal50x\Tashil\Models\Package;

interface PackageRepositoryInterface
{
    /**
     * Create a new package.
     */
    public function create(array $data): Package;

    /**
     * Create or update a package by slug.
     */
    public function updateOrCreate(array $attributes, array $values): Package;

    /**
     * Sync features for a package (without detaching existing).
     */
    public function syncFeatures(Package $package, array $syncData): void;
}
