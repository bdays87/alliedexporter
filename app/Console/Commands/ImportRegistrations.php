<?php

namespace App\Console\Commands;

use App\Models\Newcustomer;
use App\Models\Registration;
use Carbon\Carbon;
use Illuminate\Console\Command;

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

    protected function parseDate(?string $dateString): ?string
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
