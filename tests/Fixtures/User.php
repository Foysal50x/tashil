<?php

namespace Foysal50x\Tashil\Tests\Fixtures;

use Foysal50x\Tashil\Contracts\Subscribable;
use Foysal50x\Tashil\Traits\HasSubscriptions;
use Illuminate\Database\Eloquent\Model;

/**
 * Fake User model for testing the HasSubscriptions trait. Implements
 * Subscribable so it satisfies library type-hints the same way a real
 * host User class would.
 */
class User extends Model implements Subscribable
{
    use HasSubscriptions;

    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;
}
