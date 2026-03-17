<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Newcustomerapplication extends Model
{
    protected $table = 'customerapplications';
    protected $connection = 'mysql2';

    public function customerprofession()
    {
        return $this->belongsTo(Newcustomerprofession::class, "customerprofession_id", "id");
    }

    public function registertype()
    {
        return $this->belongsTo(Registertype::class);
    }

    public function customer()
    {
        return $this->belongsTo(Newcustomer::class, "customer_id", "id");
    }
}
