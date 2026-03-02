<?php

namespace App\Console\Commands;

use App\Models\Newcustomer;
use App\Models\Registration;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
class ImportRegistrations extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:import-registrations';

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
        $registrations = Registration::with("profession")->get();
        foreach ($registrations as $registration) {
            // Check if registration already exists
            $existingRegistration = \App\Models\Newcustomerregistration::where('id', $registration->Id)->first();
            if ($existingRegistration) {
                $this->info('Registration ' . $registration->Id . ' already exists. Skipping...');
                continue;
            }

            $checkcustomer = Newcustomer::where('id', $registration->CustomerId)->first();
            if ($checkcustomer) {
                $checkprofession = \App\Models\Newprofession::where('name', $registration->profession->Description)->where("prefix", $registration->profession->Prefix)->first();
                if ($checkprofession) {
                    $checkcustomerprofession = \App\Models\Newcustomerprofession::where('customer_id', $registration->CustomerId)->where('profession_id', $checkprofession->id)->first();
                    if ($checkcustomerprofession) {
                        $regdate = $this->parseDate($registration->RegistrationDate);
                        $newcustomerregistration = new \App\Models\Newcustomerregistration();
                        $newcustomerregistration->id = $registration->Id;
                        $newcustomerregistration->customer_id = $registration->CustomerId;
                        $newcustomerregistration->customerprofession_id = $checkcustomerprofession->id;
                        $newcustomerregistration->status = "APPROVED";
                        $newcustomerregistration->certificatenumber = $registration->CertificateNumber;
                        $newcustomerregistration->registrationdate = $regdate;
                        $newcustomerregistration->year = Carbon::parse($regdate)->year;

                           // Handle C# DateTimeOffset format and convert to Laravel timestamp
                        $dateCreated = $this->parseDateTimeOffset($registration->DateCreated);
                        // If DateCreated is DateTime.MinValue, try to use DateUpdated instead
                        if (! $dateCreated) {
                            $dateCreated = $this->parseDateTimeOffset($registration->DateUpdated);
                        }
                        $newcustomerregistration->created_at = $dateCreated ?? now();
                        $newcustomerregistration->updated_at = $this->parseDateTimeOffset($registration->DateUpdated);
                        $newcustomerregistration->save();

                        $this->info('Imported Registration ID: ' . $registration->Id . ' for Customer ID: ' . $registration->CustomerId);
                    } else {
                        $this->error('Customer Profession not found for Registration ID: ' . $registration->Id . ' and Customer ID: ' . $registration->CustomerId);
                    }
                } else {
                    $this->error('Profession not found for Registration ID: ' . $registration->Id . ' and Profession ID: ' . $registration->ProfessionId);
                }
            } else {
                $this->error('Customer not found for Registration ID: ' . $registration->Id . ' and Customer ID: ' . $registration->CustomerId);
            }
            //$this->info('Imported Registration: ' . $registration->Id);
        }
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


    protected function parseDateoo(?string $dateString): ?string
    {
        if (empty($dateString)) {
            return null;
        }

        $dateString = trim($dateString);

        // Try to parse DD/MM/YYYY format first
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $dateString, $matches)) {
            $day = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
            $month = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
            $year = $matches[3];
            $dateString = "{$year}-{$month}-{$day}";
        }

        // Try to parse using Carbon
        try {
            $date = Carbon::parse($dateString);

            return $date->format('Y-m-d');
        } catch (\Exception $e) {
            return null;
        }
    }
}
