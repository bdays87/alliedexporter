<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Invoice;

class Customerapplication extends Model
{
    protected $table = 'customerapplication';
    public $timestamps = false;

    const CREATED_AT = 'DateCreated';
    const UPDATED_AT = 'DateUpdated';

    protected $fillable = [
        'Id',
        'CustomerId',
        'CustomerProfessionId',
        'ApplicationTypeId',
        'RenewalCategoryId',
        'RegisterCategoryId',
        'RenewalPeriod',
        'PaymentItemId',
        'RenewalStatusId',
        'balance',
        'Cdpoints',
        'Placement',
        'CertificateNumber',
        'ApprovalStatus',
        'RegistrarStatus',
        'AccountStatus',
        'DateCreated',
        'DateUpdated',
    ];

    public function invoice()
    {
        return $this->hasOne(Applicationinvoice::class, 'CustomerApplicationId', 'Id');
    }

    public function customer()
    {
        return $this->hasOne(Customer::class, 'Id', 'CustomerId');
    }
}
