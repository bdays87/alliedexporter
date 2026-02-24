<?php

namespace App\Console\Commands;

use App\Models\Customerapplication;
use App\Models\Newcustomer;
use App\Models\Newcustomerapplication;
use App\Models\Newcustomerprofession;
use Illuminate\Console\Command;
use Carbon\Carbon;
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
       $customerapplications = Customerapplication::with('customer')
                                ->whereIn('ApprovalStatus', ['AWAITING', 'APPROVED'])
                                ->get();

        foreach ($customerapplications as $customerapplication) {

            $customer = Newcustomer::where('id', $customerapplication->CustomerId)->first();
            if (! $customer) {
                $this->error('Customer not found for application id: '.$customerapplication->Id);

                continue;
            }

            $customerprofession = Newcustomerprofession::where('id', $customerapplication->CustomerProfessionId)->first();
            if (! $customerprofession) {
                $this->error('Customer Profession not found for application id: '.$customerapplication->Id);

                continue;
            }

            // Default status
            $status = 'PENDING';

            // Status logic
            if ($customerapplication->RegistrarStatus == 0 && $customerapplication->AccountStatus == 0) {
                $status = 'AWAITING';
            } elseif ($customerapplication->RegistrarStatus == 1 && $customerapplication->AccountStatus == 0) {
                $status = 'AWAITING';
            } elseif ($customerapplication->RegistrarStatus == 1 && $customerapplication->AccountStatus == 1 && $customerapplication->ApprovalStatus == 'AWAITING') {
                $status = 'AWAITING';
            } else {
                $status = 'APPROVED';
            }

            // Create new application
            $newcustomerapplication = new Newcustomerapplication;
            $newcustomerapplication->id = $customerapplication->Id;
            $newcustomerapplication->customer_id = $customer->id;
            $newcustomerapplication->customerprofession_id = $customerprofession->id;
            $newcustomerapplication->status = $status;
            $newcustomerapplication->certificate_number = $customerapplication->CertificateNumber;
            $newcustomerapplication->certificate_expiry_date = $customerapplication->RenewalPeriod.'-12-31';
            $newcustomerapplication->year = $customerapplication->RenewalPeriod;
            $newcustomerapplication->registertype_id = $customerprofession->registertype_id ?? 1;
            $newcustomerapplication->registration_date = $customerapplication->DateCreated;
            $newcustomerapplication->applicationtype_id = $customerapplication->ApplicationTypeId;
            $newcustomerapplication->uuid = \Str::uuid();

            // Handle C# DateTimeOffset format and convert to Laravel timestamp
            $dateCreated = $this->parseDateTimeOffset($customerapplication->DateCreated);
            // If DateCreated is DateTime.MinValue, try to use DateUpdated instead
            if (! $dateCreated) {
                $dateCreated = $this->parseDateTimeOffset($customerapplication->DateUpdated);
            }
            $newcustomerapplication->created_at = $dateCreated ?? now();
            $newcustomerapplication->updated_at = $this->parseDateTimeOffset($customerapplication->DateUpdated);
            $newcustomerapplication->save();
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
