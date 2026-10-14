<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    protected $table = 'library.user';

    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['password_hash'];
}
