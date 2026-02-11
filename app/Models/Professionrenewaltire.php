<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Professionrenewaltire extends Model
{
     public function renewaltire(){
        return $this->hasOne(Renewaltire::class, 'Id', 'RenewalTireId');
    }
}
