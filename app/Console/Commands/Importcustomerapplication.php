<?php

namespace App\Console\Commands;

use App\Models\Customerapplication;
use App\Models\Newcustomer;
use App\Models\Newcustomerapplication;
use App\Models\Newcustomerprofession;
use Illuminate\Console\Command;
use Carbon\Carbon;
use Illuminate\Support\Str;
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
        $chunkSize   = 500;
        $currentYear = (int) now()->year;

        // Pre-load all lookup tables into memory — eliminates per-row SELECT queries
        $existingIds         = Newcustomerapplication::pluck('id')->flip()->all();
        $existingCustomerIds = Newcustomer::pluck('id')->flip()->all();
        $customerprofessions = Newcustomerprofession::all()->keyBy('id');

        $total = Customerapplication::whereIn('ApprovalStatus', ['AWAITING', 'APPROVED'])
            ->where('RenewalPeriod', '>=', 2025)
            ->count();
        $this->info('Total Applications Found (RenewalPeriod >= 2025): ' . $total);

        $counter = 0;

        Customerapplication::whereIn('ApprovalStatus', ['AWAITING', 'APPROVED'])
            ->where('RenewalPeriod', '>=', 2025)
            ->chunkById($chunkSize, function ($customerapplications) use (
                &$counter, &$existingIds, $existingCustomerIds, $customerprofessions, $currentYear
            ) {
                $rows = [];

                foreach ($customerapplications as $customerapplication) {
                    $id = $customerapplication->Id;

                    try {
                        // Skip fully pending applications (nothing approved at all)
                        if ($customerapplication->RegistrarStatus == 0 && $customerapplication->AccountStatus == 0 && $customerapplication->ApprovalStatus == 'PENDING') {
                            $this->warn('Skipping PENDING application ID: ' . $id);
                            continue;
                        }

                        // For 2025 up to last year: APPROVED only
                        if ($customerapplication->RenewalPeriod >= 2025 && $customerapplication->RenewalPeriod < $currentYear && $customerapplication->ApprovalStatus !== 'APPROVED') {
                            $this->warn('Skipping non-APPROVED application ID: ' . $id . ' (RenewalPeriod: ' . $customerapplication->RenewalPeriod . ', Status: ' . $customerapplication->ApprovalStatus . ')');
                            continue;
                        }

                        // For current year: AWAITING and APPROVED only
                        if ($customerapplication->RenewalPeriod == $currentYear && !in_array($customerapplication->ApprovalStatus, ['AWAITING', 'APPROVED'])) {
                            $this->warn('Skipping application ID: ' . $id . ' (RenewalPeriod: ' . $customerapplication->RenewalPeriod . ', Status: ' . $customerapplication->ApprovalStatus . ')');
                            continue;
                        }

                        if (isset($existingIds[$id])) {
                            continue;
                        }

                        if (!isset($existingCustomerIds[$customerapplication->CustomerId])) {
                            $this->error('Customer not found for application ID: ' . $id);
                            continue;
                        }

                        $customerprofession = $customerprofessions->get($customerapplication->CustomerProfessionId);
                        if (!$customerprofession) {
                            $this->error('Customer Profession not found for application ID: ' . $id);
                            continue;
                        }

                        // Resolve status
                        if ($customerapplication->RegistrarStatus == 1 && $customerapplication->AccountStatus == 1 && $customerapplication->ApprovalStatus == 'AWAITING') {
                            $status = 'AWAITING'; $registration = 1; $accounts = 1;
                        } elseif ($customerapplication->RegistrarStatus == 1 && $customerapplication->AccountStatus == 0 && $customerapplication->ApprovalStatus == 'AWAITING') {
                            $status = 'AWAITING'; $registration = 1; $accounts = 0;
                        } elseif ($customerapplication->ApprovalStatus === 'APPROVED') {
                            $status = 'APPROVED'; $registration = 1; $accounts = 1;
                        } else {
                            $status = 'PENDING'; $registration = 0; $accounts = 0;
                        }

                        $apptype = $customerapplication->RenewalCategoryId == 4 ? 3 : $customerapplication->ApplicationTypeId;

                        $dateCreated = $this->parseDateTimeOffset($customerapplication->DateCreated);
                        if (!$dateCreated) {
                            $dateCreated = $this->parseDateTimeOffset($customerapplication->DateUpdated);
                        }

                        $rows[] = [
                            'id'                      => $id,
                            'customer_id'             => $customerapplication->CustomerId,
                            'customerprofession_id'   => $customerprofession->id,
                            'status'                  => $status,
                            'registration'            => $registration,
                            'accounts'                => $accounts,
                            'certificate_number'      => $customerapplication->CertificateNumber,
                            'certificate_expiry_date' => $customerapplication->RenewalPeriod . '-12-31',
                            'year'                    => $customerapplication->RenewalPeriod,
                            'registertype_id'         => $customerprofession->registertype_id ?? 1,
                            'registration_date'       => $customerapplication->DateCreated,
                            'applicationtype_id'      => $apptype,
                            'uuid'                    => Str::uuid()->toString(),
                            'created_at'              => $dateCreated ?? now(),
                            'updated_at'              => $this->parseDateTimeOffset($customerapplication->DateUpdated),
                        ];

                        $existingIds[$id] = true;

                    } catch (\Exception $e) {
                        $this->error('Error processing Application ID: ' . $id . ' — ' . $e->getMessage());
                    }
                }

                if (!empty($rows)) {
                    try {
                        Newcustomerapplication::insertOrIgnore($rows);
                        $counter += count($rows);
                        $this->info('Inserted ' . count($rows) . ' applications. Total so far: ' . $counter);
                    } catch (\Exception $e) {
                        $this->error('Bulk insert failed, falling back row-by-row: ' . $e->getMessage());
                        foreach ($rows as $row) {
                            try {
                                Newcustomerapplication::insertOrIgnore([$row]);
                                $counter++;
                            } catch (\Exception $ex) {
                                $this->error('Failed Application ID ' . $row['id'] . ': ' . $ex->getMessage());
                            }
                        }
                    }
                }
            }, 'Id');

        $this->info('Total Imported Applications: ' . $counter);
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
