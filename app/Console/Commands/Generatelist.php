<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Customerprofession;

class Generatelist extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:generatelist';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Parse date from multiple possible formats
     */
    private function parseDate($dateString, $outputFormat = 'd-m-Y')
    {
        if (empty($dateString)) {
            return '';
        }

        // Try different input formats
        $formats = ['d/m/Y', 'Y-m-d', 'Y/m/d', 'd-m-Y'];

        foreach ($formats as $format) {
            try {
                $date = Carbon::createFromFormat($format, $dateString);
                return $date->format($outputFormat);
            } catch (\Exception $e) {
                continue;
            }
        }

        // If all formats fail, try Carbon's parse as last resort
        try {
            return Carbon::parse($dateString)->format($outputFormat);
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * Get year from date in multiple possible formats
     */
    private function getYearFromDate($dateString)
    {
        if (empty($dateString)) {
            return '';
        }

        // Try different input formats
        $formats = ['d/m/Y', 'Y-m-d', 'Y/m/d', 'd-m-Y'];

        foreach ($formats as $format) {
            try {
                $date = Carbon::createFromFormat($format, $dateString);
                return $date->year;
            } catch (\Exception $e) {
                continue;
            }
        }

        // If all formats fail, try Carbon's parse as last resort
        try {
            return Carbon::parse($dateString)->year;
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $customerProfessions = Customerprofession::with('customer.title', 'customer.customercdps', 'customerapplications', 'profession.professionrenewaltire.renewaltire', 'customer.gender', 'customer.user')->get();

        // Create CSV file
        $filename = 'customer_list_' . date('Y-m-d_His') . '.csv';
        $filepath = storage_path('app/' . $filename);

        $file = fopen($filepath, 'w');

        // Write CSV headers
        $headers = [
            'name',
            'surname',
            'email',
            'phone',
            'gender',
            'identificationnumber',
            'dob',
            'identificationtype',
            'nationality',
            'address',
            'placeofbirth',
            'profession',
            'tire',
            'registrationnumber',
            'registrationyear',
            'practisingcertificatenumber',
            'registertype',
            'last_renewal_year',
            'last_renewal_year_cdp_points',
            'last_renewal_expire_date',
            'rawpassword'
        ];
        fputcsv($file, $headers);

        foreach ($customerProfessions as $customerProfession) {
            $email = $customerProfession?->customer?->Email;
            if ($email == null) {
                $email = $customerProfession?->customer?->user?->Email;
            }
            $last_renewal_year = "";
            $last_renewal_year_cdp_points = "";
            $last_renewal_expire_date = "";
            if ($customerProfession?->customerapplications?->count() > 0) {
                $last_renewal_year = $customerProfession?->customerapplications?->last()->RenewalPeriod;
                if ($customerProfession?->customer?->customercdps?->count() > 0) {
                    $last_renewal_year_cdp_points = $customerProfession?->customer?->customercdps?->where('RenewalPeriod', $last_renewal_year)->sum('Points');
                }

                $last_renewal_expire_date = "31-12-" . $last_renewal_year;
            }
            $name = $customerProfession?->customer?->FirstName ?? '';
            $surname = $customerProfession?->customer?->LastName ?? '';
            $phone = $customerProfession?->customer?->Phone ?? '';
            $gender = $customerProfession?->customer?->gender?->Name ?? '';
            $identificationnumber = $customerProfession?->customer?->IDnumber ?? '';
            $nationality = "Zimbabwe";
            $dob = $this->parseDate($customerProfession?->customer?->Dob);
            $address = "No address";
            $placeofbirth = "No place of birth";
            $profession = $customerProfession?->profession?->Name ?? '';
            $tire = $customerProfession?->profession?->professionrenewaltire?->renewaltire?->Name ?? '';
            $registrationnumber = $customerProfession?->customer?->Prefix . $customerProfession?->customer?->RegistrationNumber ?? '';
            $registrationyear = $this->getYearFromDate($customerProfession?->customer?->RegistrationDate);
            $registertype = $customerProfession?->customer?->RegisterName ?? '';
            $identificationtype = 'NationalID'; // Add this field if available in your database
            $practisingcertificatenumber = $customerProfession?->customer?->CertificateNumber ?? ''; // Add this field if available in your database
            $rawpassword = $customerProfession?->customer?->RawPassword ?? '';

            // Write row to CSV
            $row = [
                $name,
                $surname,
                $email ?? '',
                $phone,
                $gender,
                $identificationnumber,
                $dob,
                $identificationtype,
                $nationality,
                $address,
                $placeofbirth,
                $profession,
                $tire,
                $registrationnumber,
                $registrationyear,
                $practisingcertificatenumber,
                $registertype,
                $last_renewal_year,
                $last_renewal_year_cdp_points,
                $last_renewal_expire_date,
                $rawpassword
            ];
            fputcsv($file, $row);
        }

        fclose($file);

        $this->info("CSV file created successfully: {$filepath}");
        $this->info("Total records exported: " . $customerProfessions->count());
    }
}
