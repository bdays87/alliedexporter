<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Newuser extends Model
{
    protected $connection = 'mysql2';
    protected $table = 'users';
}
