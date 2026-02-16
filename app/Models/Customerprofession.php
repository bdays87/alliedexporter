<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customerprofession extends Model
{
    public function customer()
    {
        return $this->belongsTo(Customer::class, 'CustomerId', 'Id');
    }

    public function profession()
    {
        return $this->belongsTo(Profession::class, 'ProfessionId', 'Id');
    }
    public function customerapplications()
    {
        return $this->hasMany(Customerapplication::class, 'CustomerProfessionId', 'Id')->where('ApprovalStatus', 'APPROVED');
    }
}
