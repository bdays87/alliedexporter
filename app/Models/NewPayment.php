<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NewPayment extends Model
{
    protected $connection = 'mysql2';

    protected $table = 'suspenses';
}
