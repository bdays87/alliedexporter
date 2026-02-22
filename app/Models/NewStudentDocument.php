<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NewStudentDocument extends Model
{
    protected $connection = 'mysql2';

    protected $table = 'studentprofessions';
}
