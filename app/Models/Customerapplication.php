<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Invoice;
class Customerapplication extends Model
{
    protected $table = 'customerapplication';
    public function invoice(){
        return $this->hasOne(Applicationinvoice::class, 'CustomerApplicationId', 'Id');
    }
    public function customer(){
        return $this->hasOne(Customer::class, 'Id', 'CustomerId');
    }
}
