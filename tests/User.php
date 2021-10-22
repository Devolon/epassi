<?php

namespace Devolon\EPassi\Tests;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as BaseUser;

class User extends BaseUser
{
    use HasFactory;

    protected static function newFactory()
    {
        return new UserFactory();
    }
}
