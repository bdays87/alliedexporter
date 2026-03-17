<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NewCustomerCPDAttachment extends Model
{
   protected $connection = 'mysql2';
   protected $table = 'mycdpattachments';

   protected $fillable = [
        'id',
        'mycdp_id',
        'type',
        'file',
        'created_at',
        'updated_at'
    ];
}
