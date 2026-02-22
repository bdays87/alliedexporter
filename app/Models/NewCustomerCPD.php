<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NewCustomerCPD extends Model
{
     protected $connection = 'mysql2';
     protected $table = 'mycdps';

      protected $fillable = [
        'id',
        'customerprofession_id',
        'title',
        'year',
        'description',
        'type',
        'points',
        'duration',
        'durationunit',
        'user_id',
        'status',
        'comment',
        'assessed_by',
        'assessed_at',
        'created_at',
        'updated_at'
    ];
}
