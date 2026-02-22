<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customercdp extends Model
{
      public function customer(){
        return $this->hasOne(Customer::class, 'Id', 'CustomerId');
    }
}
