<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NewReceipt extends Model
{
    protected $connection = 'mysql2';

    protected $table = 'receipts';
}
