<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NewCustomerContact extends Model
{
    protected $connection = 'mysql2';
    protected $table = 'customercontacts';

     protected $fillable = [
        'id',
        'customer_id',
        'name',
        'relationship',
        'primaryphone',
        'secondaryphone',
        'email',
        'created_at',
        'updated_at'
    ];
}
