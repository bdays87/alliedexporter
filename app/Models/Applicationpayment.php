<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Applicationpayment extends Model
{
    public function applicationinvoice(){
        return $this->hasOne(Applicationinvoice::class, 'Id', 'ApplicationInvoiceId');
    }
     public function currency(){
        return $this->hasOne(Currency::class, 'Id', 'CurrencyId');
    }
    public function exchangeRate(){
        return $this->hasOne(Exchangerate::class, 'Id', 'ExchangeRateId');
    }
    public function paymentchannel(){
        return $this->hasOne(Paymentchannel::class, 'Id', 'PaymentChannelId');
    }
}
