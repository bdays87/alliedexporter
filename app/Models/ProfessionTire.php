<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProfessionTire extends Model
{
    protected $table = 'professionrenewaltires';
     public function renewaltire(){
        return $this->hasOne(Renewaltire::class, 'Id', 'RenewalTireId');
    }
}
