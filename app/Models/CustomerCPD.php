<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerCPD extends Model
{
       protected $table = 'customercdps';
        protected $connection = 'mysql';
        public function customer(){
            return $this->hasOne(Customer::class, 'Id', 'CustomerId');
        }
}
