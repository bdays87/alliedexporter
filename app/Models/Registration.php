<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Registration extends Model
{

    protected $table = 'registrations';

    public function profession()
    {
        return $this->belongsTo(Profession::class, "ProfessionId", "Id");
    }
}
