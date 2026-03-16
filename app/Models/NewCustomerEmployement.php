<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NewCustomerEmployement extends Model
{
    protected $connection = 'mysql2';
     protected $table = 'customeremployments';

      protected $fillable = [
        'id',
        'customer_id',
        'companyname',
        'position',
        'start_date',
        'end_date',
        'phone',
        'email',
        'address',
        'contactperson',
        'created_at',
        'updated_at'
    ];
}
