<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerEmployement extends Model
{
    protected $table = 'customeremployments';

     public function customer(){
        return $this->hasOne(Customer::class, 'Id', 'CustomerId');
    }
}
