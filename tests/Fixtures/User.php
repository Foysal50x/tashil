<?php

namespace Foysal50x\Tashil\Tests\Fixtures;

use Foysal50x\Tashil\Traits\HasSubscriptions;
use Illuminate\Database\Eloquent\Model;

/**
 * Fake User model for testing the HasSubscriptions trait.
 */
class User extends Model
{
    use HasSubscriptions;

    protected $table = 'users';
    protected $guarded = [];
    public $timestamps = false;
}
