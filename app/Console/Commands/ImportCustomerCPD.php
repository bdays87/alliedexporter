<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\CustomerCPD;
use App\Models\Newcustomerprofession;
use App\Models\NewCustomerCPD;
use App\Models\Newcustomeruser;
use Illuminate\Support\Str;
use Carbon\Carbon;

class ImportCustomerCPD extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:import-customer-c-p-d';

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
        $this->info("Importing CPD records...");

        $cpds = CustomerCPD::with('customer')->get();

        foreach ($cpds as $cpd) {

            // Get profession
            $profession = Newcustomerprofession::where('customer_id', $cpd->CustomerId)->first();

            // Get user mapping
            $customeruser = Newcustomeruser::where('customer_id', $cpd->CustomerId)->first();

            if (!$profession) {
                $this->warn("No profession for customer ID: {$cpd->CustomerId}");
                continue;
            }

            // Extract user id safely
            $userId = $customeruser ? $customeruser->user_id : null;
            $createdAt = $this->parseDateTimeOffset($cpd->DateCreated) ?? now();
            $updatedAt = $this->parseDateTimeOffset($cpd->DateUpdated) ?? $createdAt;
            NewCustomerCPD::create([
                'id' => $cpd->id,
                'customerprofession_id' => $profession->id,
                'title' => 'Imported CPD',
                'year' => $cpd->RenewalPeriod,
                'description' => 'Imported CPD record for customer ID: '.$cpd->CustomerId,
                'type' => 'PHYSICAL',
                'points' => $cpd->Points,
                'durationunit' => "HOURS",
                'user_id' => $userId,   // ✅ fixed
                'status' => 'PROCESSED',
                'comment' => 'Imported from old system',
                'assessed_by' => 1,//
                'assessed_at' => $updatedAt,
                'created_at' => $createdAt,
                'updated_at' => $updatedAt,
            ]);
        }

        $this->info("CPD Import Completed!");
    }





// Parse DOB
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
