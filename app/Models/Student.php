<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Student extends Model
{
    public function profession()
    {
        return $this->hasOne(Profession::class, 'Id', 'ProfessionId');
    }
}
