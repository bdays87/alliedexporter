<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Newinvoice extends Model
{
    protected $table = 'invoices';
    protected $connection = 'mysql2';
}
