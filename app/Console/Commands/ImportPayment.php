<?php

namespace App\Console\Commands;

use App\Models\Applicationpayment;
use App\Models\Newcurrency;
use App\Models\Newcustomer;
use App\Models\Newcustomerapplication;
use App\Models\NewPayment;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Carbon\Carbon;
class ImportPayment extends Command
{
    protected $signature = 'app:import-payment';

    protected $description = 'Import payments into suspenses table';

    public function handle()
    {
        $chunkSize = 500;

        // Pre-load lookup tables into memory — eliminates per-row SELECT queries
        $existingIds = NewPayment::pluck('id')->flip()->all();

        $currencies = Newcurrency::all()->keyBy('name');

        $existingCustomerIds = Newcustomer::pluck('id')->flip()->all();

        // Only import payments linked to applications already imported
        $importedApplicationIds = Newcustomerapplication::pluck('id')->flip()->all();

        $total = Applicationpayment::count();
        $this->info('Total Payments Found: ' . $total);

        $counter = 0;

        Applicationpayment::with(['applicationinvoice.customerapplication', 'paymentchannel', 'currency'])
            ->chunkById($chunkSize, function ($payments) use (
                &$counter, &$existingIds, $currencies, $existingCustomerIds, $importedApplicationIds
            ) {
                $rows = [];

                foreach ($payments as $payment) {
                    $id = $payment->Id;

                    try {
                        if (isset($existingIds[$id])) {
                            continue;
                        }

                        if (!$payment->applicationinvoice) {
                            $this->error('Invoice missing for Payment ID: ' . $id);
                            continue;
                        }

                        $appId = $payment->applicationinvoice->CustomerApplicationId ?? null;
                        if (!$appId || !isset($importedApplicationIds[$appId])) {
                            $this->warn('Skipping Payment ID: ' . $id . ' — Application ID ' . $appId . ' not imported yet.');
                            continue;
                        }

                        $customer_id = $payment->applicationinvoice->customerapplication->CustomerId ?? null;
                        if (!$customer_id || !isset($existingCustomerIds[$customer_id])) {
                            $this->error('Customer missing for Payment ID: ' . $id);
                            continue;
                        }

                        if (!$payment->currency) {
                            $this->error('Currency relation missing for Payment ID: ' . $id);
                            continue;
                        }

                        $currency = $currencies->get($payment->currency->Name);
                        if (!$currency) {
                            $this->error('Currency "' . $payment->currency->Name . '" not found for Payment ID: ' . $id);
                            continue;
                        }

                        $dateCreated = $this->parseDateTimeOffset($payment->DateCreated);
                        if (!$dateCreated) {
                            $dateCreated = $this->parseDateTimeOffset($payment->DateUpdated);
                        }

                        $rows[] = [
                            'id'          => $id,
                            'customer_id' => $customer_id,
                            'currency_id' => $currency->id,
                            'uuid'        => Str::uuid()->toString(),
                            'source'      => $payment->paymentchannel->Name ?? 'Cash',
                            'source_id'   => $id,
                            'amount'      => floatval($payment->Amount),
                            'createdby'   => 1,
                            'status'      => 'PENDING',
                            'created_at'  => $dateCreated ?? now(),
                            'updated_at'  => $this->parseDateTimeOffset($payment->DateUpdated),
                        ];

                        $existingIds[$id] = true;

                    } catch (\Exception $e) {
                        $this->error('Error processing Payment ID: ' . $id . ' — ' . $e->getMessage());
                    }
                }

                if (!empty($rows)) {
                    try {
                        NewPayment::insertOrIgnore($rows);
                        $counter += count($rows);
                        $this->info('Inserted ' . count($rows) . ' payments. Total so far: ' . $counter);
                    } catch (\Exception $e) {
                        $this->error('Bulk insert failed, falling back row-by-row: ' . $e->getMessage());
                        foreach ($rows as $row) {
                            try {
                                NewPayment::insertOrIgnore([$row]);
                                $counter++;
                            } catch (\Exception $ex) {
                                $this->error('Failed Payment ID ' . $row['id'] . ': ' . $ex->getMessage());
                            }
                        }
                    }
                }
            }, 'Id');

        $this->info('Import completed. Total imported: ' . $counter);
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
