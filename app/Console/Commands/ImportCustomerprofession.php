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

            // Check if customer profession already exists
            $existingCustomerProfession = Newcustomerprofession::where('id', $id)->first();
            if ($existingCustomerProfession) {
                $this->info('Customer Profession ' . $id . ' already exists. Skipping...');
                continue;
            }

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
                $status = "APPROVED";
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

                // Handle C# DateTimeOffset format and convert to Laravel timestamp
            $dateCreated = $this->parseDateTimeOffset($customerprofession->DateCreated);
            // If DateCreated is DateTime.MinValue, try to use DateUpdated instead
            if (! $dateCreated) {
                $dateCreated = $this->parseDateTimeOffset($customerprofession->DateUpdated);
            }
            $newcustomerprofession->created_at = $dateCreated ?? now();
            $newcustomerprofession->updated_at = $this->parseDateTimeOffset($customerprofession->DateUpdated);
                $newcustomerprofession->save();
                $counter++;
            } else {
                $this->error('Profession not found for Customer Profession ID: ' . $customerprofession->ProfessionId . ' and Profession Description: ' . $customerprofession->profession->Description . ' and Profession Prefix: ' . $customerprofession->profession->Prefix);
            }
            //$this->info('Imported Customer Profession: ' . $counter);
        }
        $this->info('Total Imported Customer Profession: ' . $counter);
    }







   protected function parseDate($dateString)
    {
        if (! $dateString) {
            return null;
        }
        $dateString = trim($dateString);

        if ($dateString == '0001-01-01' || $dateString == '0001-01-01 00:00:00') {
            return null;
        }

        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $dateString, $m)) {
            return "{$m[3]}-".str_pad($m[2], 2, '0', STR_PAD_LEFT).'-'.str_pad($m[1], 2, '0', STR_PAD_LEFT);
        }

        try {
            return Carbon::parse($dateString)->format('Y-m-d');
        } catch (\Exception $e) {
            return null;
        }
    }

    // ✅ Clean C# DateTime.MinValue
    protected function cleanDate($date)
    {
        if (! $date) {
            return null;
        }

        $date = trim((string) $date);

        if ($date == '0001-01-01 00:00:00' || $date == '0001-01-01') {
            return null;
        }

        try {
            return Carbon::parse($date)->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            return null;
        }
    }

    // Parse C# DateTimeOffset to Laravel timestamp
    protected function parseDateTimeOffset($date)
    {
        if (! $date) {
            return null;
        }

        $date = trim((string) $date);

        // Handle C# DateTimeOffset JSON format: /Date(1234567890000+0200)/
        if (preg_match('/^\/Date\((-?\d+)([+-]\d{4})?\)\/$/', $date, $matches)) {
            $timestamp = (int) $matches[1];
            // C# timestamp is in milliseconds, convert to seconds
            $timestamp = $timestamp / 1000;

            return Carbon::createFromTimestamp($timestamp)->format('Y-m-d H:i:s');
        }

        // Handle ISO 8601 format with offset: 2024-01-15T10:30:00+02:00
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $date)) {
            try {
                return Carbon::parse($date)->format('Y-m-d H:i:s');
            } catch (\Exception $e) {
                return null;
            }
        }

        // Fall back to cleanDate for standard formats
        return $this->cleanDate($date);
    }
















}
