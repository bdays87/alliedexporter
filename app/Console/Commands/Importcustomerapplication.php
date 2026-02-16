<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Customerapplication;
use App\Models\Newcustomer;
use App\Models\Newcustomerapplication;
use App\Models\Newcustomerprofession;
use Illuminate\Console\Command;

class Importcustomerapplication extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:importcustomerapplication';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $customerapplications = Customerapplication::with("customer")->get();
        foreach ($customerapplications as $customerapplication) {
            $customer = Newcustomer::where("id", $customerapplication->CustomerId)->first();
            if ($customer) {
                $customerprofession = Newcustomerprofession::where("id", $customerapplication->CustomerProfessionId)->first();
                if ($customerprofession) {
                    $status = "PENDING";
                    if ($customerapplication->RegistrarStatus == 1 && $customerapplication->AccountStatus == 0) {
                        $status = "AWAITING_FINANCE";
                    } elseif ($customerapplication->RegistrarStatus == 1 && $customerapplication->AccountStatus == 1 && $customerapplication->ApprovalStatus == "PENDING") {
                        $status = "AWAITING_REGISTRAR";
                    } else {
                        $status = "APPROVED";
                    }

                    $newcustomerapplication = new Newcustomerapplication();
                    $newcustomerapplication->id = $customerapplication->Id;
                    $newcustomerapplication->customer_id = $customer->id;
                    $newcustomerapplication->customerprofession_id = $customerprofession->id;
                    $newcustomerapplication->status = $status;
                    $newcustomerapplication->certificate_number = $customerapplication->CertificateNumber;
                    $newcustomerapplication->certificate_expiry_date =  $customerapplication->RenewalPeriod . "-12-31";
                    $newcustomerapplication->year = $customerapplication->RenewalPeriod;
                    $newcustomerapplication->registertype_id = $customerprofession->registertype_id ?? 1;
                    $newcustomerapplication->registration_date = $customerapplication->DateCreated;
                    $newcustomerapplication->applicationtype_id = $customerapplication->ApplicationTypeId;
                    $newcustomerapplication->save();
                } else {
                    $this->error("Customer Profession not found for application id: " . $customerapplication->Id);
                }
                //$customer_id = $customer->id;
                // $customerprofession_id

            } else {
                $this->error("Customer not found for application id: " . $customerapplication->Id);
            }
        }
    }
}
