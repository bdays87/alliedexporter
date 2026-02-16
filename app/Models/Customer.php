<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{

    public function title()
    {
        return $this->hasOne(Title::class, 'Id', 'TitleId');
    }

    public function gender()
    {
        return $this->hasOne(Gender::class, 'Id', 'GenderId');
    }
    public function User()
    {
        return $this->hasOne(User::class, 'Id', 'UserId');
    }
    public function customercdps()
    {
        return $this->hasMany(Customercdp::class, 'CustomerId', 'Id');
    }
    public function province()
    {
        return $this->hasOne(Province::class, 'Id', 'ProvinceId');
    }
    public function city()
    {
        return $this->hasOne(City::class, 'Id', 'CityId');
    }

    public function registrations()
    {
        return $this->hasMany(Registration::class, 'CustomerId', 'Id');
    }
}
