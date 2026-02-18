<?php

namespace Foysal50x\Tashil\Repositories;

use Foysal50x\Tashil\Contracts\FeatureRepositoryInterface;
use Foysal50x\Tashil\Models\Feature;

class EloquentFeatureRepository implements FeatureRepositoryInterface
{
    public function create(array $data): Feature
    {
        return Feature::create($data);
    }

    public function updateOrCreate(array $attributes, array $values): Feature
    {
        return Feature::updateOrCreate($attributes, $values);
    }
}
