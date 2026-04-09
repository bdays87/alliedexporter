<?php

namespace App\Console\Commands;

use App\Models\Customerprofession;
use App\Models\Newcustomerprofession;
use App\Models\ProfessionTire;
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
        $chunkSize = 500;

        // Pre-load all lookup tables into memory — zero per-row queries for these
        $registertypes   = Newregistertype::all();
        $professions     = Newprofession::all();
        $professionTires = ProfessionTire::all()->keyBy('ProfessionId');

        // Pre-load existing IDs — eliminates one SELECT per row
        $existingIds = Newcustomerprofession::pluck('id')->flip()->all();

        $total = Customerprofession::count();
        $this->info('Total Customer Professions: ' . $total);

        $counter = 0;

        Customerprofession::with('customer.registrations', 'profession')
            ->chunkById($chunkSize, function ($customerprofessions) use (
                &$counter, &$existingIds, $registertypes, $professions, $professionTires
            ) {
                $rows = [];

                foreach ($customerprofessions as $customerprofession) {
                    $id = $customerprofession->Id;

                    try {
                        if (isset($existingIds[$id])) {
                            continue;
                        }

                        if (!$customerprofession->profession) {
                            $this->error('Profession relation missing for Customer Profession ID: ' . $id);
                            continue;
                        }

                        $profession = $professions
                            ->where('name', $customerprofession->profession->Name)
                            ->where('prefix', $customerprofession->profession->Prefix)
                            ->first();

                        if (!$profession) {
                            $this->error('Profession not found for Customer Profession ID: ' . $id . ' — ' . $customerprofession->profession->Description . ' / ' . $customerprofession->profession->Prefix);
                            continue;
                        }

                        if (!$customerprofession->customer) {
                            $this->error('Customer not found for Customer Profession ID: ' . $id . ' Customer ID: ' . $customerprofession->CustomerId);
                            continue;
                        }

                        $registertype = $registertypes->where('name', $customerprofession->customer->RegisterName)->first();

                        // Use the eager-loaded collection (->registrations) not a new DB query (->registrations())
                        $registration = $customerprofession->customer->registrations
                            ->where('ProfessionId', $customerprofession->ProfessionId)
                            ->first();

                        // Use the pre-loaded tire map instead of one query per row
                        $tire    = $professionTires->get($customerprofession->ProfessionId);
                        $tire_id = $tire ? $tire->RenewalTireId : null;

                        $dateCreated = $this->parseDateTimeOffset($customerprofession->DateCreated);
                        if (!$dateCreated) {
                            $dateCreated = $this->parseDateTimeOffset($customerprofession->DateUpdated);
                        }

                        $rows[] = [
                            'id'                    => $id,
                            'customer_id'           => $customerprofession->CustomerId,
                            'profession_id'         => $profession->id,
                            'customertype_id'       => $customerprofession->customer->CustomerTypeId != 1 ? 3 : 1,
                            'employmentstatus_id'   => $customerprofession->customer->EmploymentStatusId,
                            'employmentlocation_id' => $customerprofession->customer->EmploymentLocationId,
                            'registertype_id'       => $registertype ? $registertype->id : null,
                            'registrationnumber'    => $customerprofession->customer->Prefix . $customerprofession->customer->RegistrationNumber,
                            'tire_id'               => $tire_id,
                            'uuid'                  => Str::uuid()->toString(),
                            'employmentsector'      => 'PUBLIC',
                            'status'                => 'APPROVED',
                            'year'                  => $registration ? Carbon::parse($registration->RegistrationDate)->year : null,
                            'created_at'            => $dateCreated ?? now(),
                            'updated_at'            => $this->parseDateTimeOffset($customerprofession->DateUpdated),
                        ];

                        $existingIds[$id] = true;

                    } catch (\Exception $e) {
                        $this->error('Error processing Customer Profession ID: ' . $id . ' — ' . $e->getMessage());
                    }
                }

                if (!empty($rows)) {
                    try {
                        Newcustomerprofession::insertOrIgnore($rows);
                        $counter += count($rows);
                        $this->info('Inserted ' . count($rows) . ' records. Total so far: ' . $counter);
                    } catch (\Exception $e) {
                        $this->error('Bulk insert failed, falling back row-by-row: ' . $e->getMessage());
                        foreach ($rows as $row) {
                            try {
                                Newcustomerprofession::insertOrIgnore([$row]);
                                $counter++;
                            } catch (\Exception $ex) {
                                $this->error('Failed Customer Profession ID ' . $row['id'] . ': ' . $ex->getMessage());
                            }
                        }
                    }
                }
            }, 'Id');

        $this->info('Total Imported Customer Professions: ' . $counter);
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
