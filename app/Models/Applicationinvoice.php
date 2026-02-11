<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Applicationpayment;
use App\Models\Customerapplication;
use App\Models\Currency;
use App\Models\Paymentitem;
class Applicationinvoice extends Model
{
    
    public function payments(){
        return $this->hasMany(Applicationpayment::class, 'ApplicationInvoiceId', 'Id');
    }
    public function customerapplication(){
        return $this->hasOne(Customerapplication::class, 'Id', 'CustomerApplicationId');
    }
    public function currency(){
        return $this->hasOne(Currency::class, 'Id', 'CurrencyId');
    }
    public  function payment_type(){
        return $this->hasOne(Paymentitem::class, 'Id', 'PaymentItemId');
    }
}
