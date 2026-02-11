<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Profession extends Model
{
     public function professionrenewaltire(){
        return $this->hasOne(Professionrenewaltire::class, 'ProfessionId', 'Id');
    }
}
