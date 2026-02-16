<?php

namespace App\Console\Commands;

use App\Models\Customerprofession;
use App\Models\Newcustomerprofession;

use App\Models\Newprofession;
use App\Models\Newregistertype;
use Carbon\Carbon;
use CurlHandle;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ImportCustomerprofession extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:import-customerprofession';

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
        $customerprofessions = Customerprofession::with('customer.registrations', 'profession', 'customerapplications')->get();
        $this->info('Total Customer Profession: ' . $customerprofessions->count());
        $counter = 0;
        $registertypes = Newregistertype::all();
        $professions = Newprofession::all();
        foreach ($customerprofessions as $customerprofession) {
            $id = $customerprofession->Id;
            $profession = $professions->where('name', $customerprofession->profession->Description)->where("prefix", $customerprofession->profession->Prefix)->first();
            if ($profession) {
                if (!$customerprofession->customer) {
                    $this->error('Customer not found for Customer Profession ID: ' . $customerprofession->Id . ' and Customer ID: ' . $customerprofession->CustomerId);
                    continue;
                }
                $registertype = $registertypes->where('name', $customerprofession->customer->RegisterName)->first();
                $registration = $customerprofession->customer->registrations()->where('ProfessionId', $customerprofession->ProfessionId)->first();

                $customer_id = $customerprofession->CustomerId;
                $profession_id = $profession->id;
                $customertype_id = $customerprofession->customer->CustomerTypeId != 1 ? 3 : 1;
                $employmentstatus_id = $customerprofession->customer->EmploymentStatusId;
                $employmentlocation_id = $customerprofession->customer->EmploymentLocationId;
                $registertype_id = $registertype ? $registertype->id : null;
                $registrationnumber = $customerprofession->customer->Prefix . $customerprofession->customer->RegistrationNumber;
                $tire_id = 1;
                $uuid = Str::uuid()->toString();
                $employmentsector = "PUBLIC";
                $status = $registration ? $registration->Status : "PENDING";
                $year =  $registration ? Carbon::parse($registration->RegistrationDate)->year : null;
                $newcustomerprofession = new Newcustomerprofession();
                $newcustomerprofession->id = $id;
                $newcustomerprofession->customer_id = $customer_id;
                $newcustomerprofession->profession_id = $profession_id;
                $newcustomerprofession->customertype_id = $customertype_id;
                $newcustomerprofession->employmentstatus_id = $employmentstatus_id;
                $newcustomerprofession->employmentlocation_id = $employmentlocation_id;
                $newcustomerprofession->registertype_id = $registertype_id;
                $newcustomerprofession->registrationnumber = $registrationnumber;
                $newcustomerprofession->tire_id = $tire_id;
                $newcustomerprofession->uuid = $uuid;
                $newcustomerprofession->employmentsector = $employmentsector;
                $newcustomerprofession->status = $status;
                $newcustomerprofession->year = $year;
                $newcustomerprofession->save();
                $counter++;
            } else {
                $this->error('Profession not found for Customer Profession ID: ' . $customerprofession->ProfessionId . ' and Profession Description: ' . $customerprofession->profession->Description . ' and Profession Prefix: ' . $customerprofession->profession->Prefix);
            }
            //$this->info('Imported Customer Profession: ' . $counter);
        }
        $this->info('Total Imported Customer Profession: ' . $counter);
    }
}
