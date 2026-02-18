<?php

namespace Foysal50x\Tashil\Contracts;

use Foysal50x\Tashil\Models\Feature;

interface FeatureRepositoryInterface
{
    /**
     * Create a new feature.
     */
    public function create(array $data): Feature;

    /**
     * Create or update a feature by slug.
     */
    public function updateOrCreate(array $attributes, array $values): Feature;
}
