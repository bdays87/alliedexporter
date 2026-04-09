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
        $chunkSize = 500;

        // Pre-load all lookup tables into memory — eliminates per-row SELECT queries
        $existingIds = \App\Models\Newcustomerregistration::pluck('id')->flip()->all();

        $existingCustomerIds = Newcustomer::pluck('id')->flip()->all();

        // Keyed by id (= old Profession.Id, preserved during import) for direct O(1) lookup
        $professions = \App\Models\Newprofession::all()->keyBy('id');

        // Keyed by customer_id|profession_id for direct lookup
        $customerprofessions = \App\Models\Newcustomerprofession::all()
            ->keyBy(fn($cp) => $cp->customer_id . '|' . $cp->profession_id);

        $total = Registration::count();
        $this->info('Total Registrations Found: ' . $total);

        $counter = 0;

        Registration::with('profession')
            ->chunkById($chunkSize, function ($registrations) use (
                &$counter, &$existingIds, $existingCustomerIds, $professions, $customerprofessions
            ) {
                $rows = [];

                foreach ($registrations as $registration) {
                    $id = $registration->Id;

                    try {
                        if (isset($existingIds[$id])) {
                            continue;
                        }

                        if (!isset($existingCustomerIds[$registration->CustomerId])) {
                            $this->error('Customer not found for Registration ID: ' . $id . ' Customer ID: ' . $registration->CustomerId);
                            continue;
                        }

                        // Use ProfessionId directly — Newprofession.id = old Profession.Id (preserved during import)
                        $checkprofession = $professions->get($registration->ProfessionId);

                        if (!$checkprofession) {
                            $this->error('Profession not found for Registration ID: ' . $id . ' ProfessionId: ' . $registration->ProfessionId);
                            continue;
                        }

                        // Look up by customer_id|profession_id using the registration's own ProfessionId
                        $cpKey                   = $registration->CustomerId . '|' . $registration->ProfessionId;
                        $this->line('Looking up cpKey: ' . $cpKey);

                        $checkcustomerprofession = $customerprofessions->get($cpKey);

                        if (!$checkcustomerprofession) {
                            $this->error('Customer Profession not found for Registration ID: ' . $id . ' Customer ID: ' . $registration->CustomerId . ' ProfessionId: ' . $registration->ProfessionId);
                            continue;
                        }

                        $regdate     = $this->parseDate($registration->RegistrationDate);
                        $dateCreated = $this->parseDateTimeOffset($registration->DateCreated);
                        if (!$dateCreated) {
                            $dateCreated = $this->parseDateTimeOffset($registration->DateUpdated);
                        }

                        $rows[] = [
                            'id'                    => $id,
                            'customer_id'           => $registration->CustomerId,
                            'customerprofession_id' => $checkcustomerprofession->id,
                            'status'                => 'APPROVED',
                            'certificatenumber'     => $registration->CertificateNumber,
                            'registrationdate'      => $regdate,
                            'year'                  => $regdate ? Carbon::parse($regdate)->year : null,
                            'created_at'            => $dateCreated ?? now(),
                            'updated_at'            => $this->parseDateTimeOffset($registration->DateUpdated),
                        ];

                        $existingIds[$id] = true;

                    } catch (\Exception $e) {
                        $this->error('Error processing Registration ID: ' . $id . ' — ' . $e->getMessage());
                    }
                }

                if (!empty($rows)) {
                    try {
                        \App\Models\Newcustomerregistration::insertOrIgnore($rows);
                        $counter += count($rows);
                        $this->info('Inserted ' . count($rows) . ' registrations. Total so far: ' . $counter);
                    } catch (\Exception $e) {
                        $this->error('Bulk insert failed, falling back row-by-row: ' . $e->getMessage());
                        foreach ($rows as $row) {
                            try {
                                \App\Models\Newcustomerregistration::insertOrIgnore([$row]);
                                $counter++;
                            } catch (\Exception $ex) {
                                $this->error('Failed Registration ID ' . $row['id'] . ': ' . $ex->getMessage());
                            }
                        }
                    }
                }
            }, 'Id');

        $this->info('Total Imported Registrations: ' . $counter);
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
