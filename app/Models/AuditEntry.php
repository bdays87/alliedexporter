<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditEntry extends Model
{
    protected $connection = 'mysql';
    protected $table = 'auditentries';
    public $timestamps = false;

    protected $casts = [
        'changes' => 'array',
    ];

    protected $fillable = [
        'Id',
        'EntityName',
        'Action',
        'EntityId',
        'UserId',
        'Timestamp',
        'Changes',
        'IpAddress',
    ];

    public function getChangesAttribute($value)
    {
        if (is_string($value)) {
            return json_decode($value, true);
        }
        return $value;
    }

    public function setChangesAttribute($value)
    {
        $this->attributes['Changes'] = is_array($value) ? json_encode($value) : $value;
    }
}
