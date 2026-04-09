<?php

namespace App\Console\Commands;
use App\Models\NewCustomerEmployement;
use App\Models\CustomerEmployement;
use Carbon\Carbon;
use Illuminate\Console\Command;
use App\Models\Newuser;
use Illuminate\Support\Str;

class ImportEmployement extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:import-employement';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    public function handle()
    {

        $countnew = NewCustomerEmployement::count();
        $oldcustoer = [];
        if ($countnew > 0) {
            $oldcustoer = CustomerEmployement::with('customer')->where('id', '>', $countnew)->get();
        } else {
            $oldcustoer  =  CustomerEmployement::with('customer')->get();
        }

         $this->info('Total Customer Employement Found: '.$oldcustoer->count());
        $counter = 0;
        foreach ($oldcustoer as $student) {
            try {
                // Check if student already exists by ID
                $existingStudent = NewCustomerEmployement::where('id', $student->Id)->first();
                if ($existingStudent) {
                    $this->info('Employement ' . $student->Id . ' already exists. Skipping...');
                    continue;
                }

                $new = new NewCustomerEmployement;
                $new->id = $student->Id;
                $new->customer_id = $student->CustomerId;
                $new->companyname = $student->Name;
                $new->position = $student->JobTitle;
                $new->start_date = $student->CommencementData;
                $new->end_date = null;
                $new->phone = $student->Phone;
                $new->email = $student->Email;
                $new->address = $student->Address;
                $new->contactperson = $student->ContactPerson;
                $new->created_at = now();
                $new->updated_at = now();

                $new->save();

                $counter++;
                $customerName = $student->customer ? $student->customer->fullname : 'Customer ID ' . $student->CustomerId;
                $this->info("Employement {$counter} imported: {$customerName}");
            } catch (\Exception $e) {
                $this->error('Error processing Employment ID: ' . $student->Id . ' — ' . $e->getMessage());
            }
        }
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
