<?php

namespace App\Console\Commands;

use App\Models\Applicationinvoice;
use App\Models\Newcustomerapplication;
use App\Models\Newcustomer;
use App\Models\Newcurrency;
use App\Models\Newinvoice;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class Importinvoices extends Command
{
    /**
     * The name and signature of the console command.
     *  app:generateinvoice            Command description
     *  app:generatelist               Command description
     *  app:generatepayment            Command description
     *  app:import-customer            Command description
     *  app:import-customerprofession  Command description
     *  app:import-registrations       Command description
     *  app:importcustomerapplication  Command description
     *  app:importinvoices             Command description
     *  app:importprofession           Command description
     *
     * @var string
     */
    protected $signature = 'app:importinvoices';

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

        // Pre-load lookup tables into memory — eliminates per-row SELECT queries
        $existingIds = Newinvoice::pluck('id')->flip()->all();

        $existingCustomerIds = Newcustomer::pluck('id')->flip()->all();

        // Keyed by currency name for O(1) lookup
        $currencies = Newcurrency::all()->keyBy('name');

        // Only import invoices whose CustomerApplicationId exists in Newcustomerapplication
        $importedApplicationIds = Newcustomerapplication::pluck('id')->flip()->all();

        $total = Applicationinvoice::whereIn('PaymentItemId', [33, 46, 45])->count();
        $this->info('Total Invoices Found: ' . $total);

        $counter = 0;

        Applicationinvoice::with('payments', 'customerapplication', 'currency')
            ->whereIn('PaymentItemId', [33, 46, 45])
            ->chunkById($chunkSize, function ($invoices) use (
                &$counter, &$existingIds, $existingCustomerIds, $currencies, $importedApplicationIds
            ) {
                $rows = [];

                foreach ($invoices as $invoice) {
                    $id = $invoice->Id;

                    try {
                        if (isset($existingIds[$id])) {
                            continue;
                        }

                        // Only import if the linked application was already imported
                        if (!isset($importedApplicationIds[$invoice->CustomerApplicationId])) {
                            $this->warn('Skipping Invoice ID: ' . $id . ' — Application ID ' . $invoice->CustomerApplicationId . ' not imported yet.');
                            continue;
                        }

                        if (!$invoice->customerapplication) {
                            $this->error('Missing customerapplication relation for Invoice ID: ' . $id);
                            continue;
                        }

                        $customer_id = $invoice->customerapplication->CustomerId;

                        if (!isset($existingCustomerIds[$customer_id])) {
                            $this->error('Customer ID ' . $customer_id . ' not found for Invoice ID: ' . $id);
                            continue;
                        }

                        if (!$invoice->currency) {
                            $this->error('Missing currency relation for Invoice ID: ' . $id);
                            continue;
                        }

                        $currency = $currencies->get($invoice->currency->Name);
                        if (!$currency) {
                            $this->error('Currency "' . $invoice->currency->Name . '" not found for Invoice ID: ' . $id);
                            continue;
                        }

                        $source      = in_array($invoice->PaymentItemId, [33, 46]) ? 'customerapplication' : 'customerprofession';
                        $description = in_array($invoice->PaymentItemId, [33, 46]) ? 'Renewal' : ($invoice->PaymentItemId == 45 ? 'New' : 'OTHER FEE');
                        $amount      = $invoice->TotalDue;
                        $paid        = $invoice->payments->sum('Amount');
                        $status      = $amount == $paid ? 'PAID' : 'AWAITING';

                        $dateCreated = $this->parseDateTimeOffset($invoice->DateCreated);
                        if (!$dateCreated) {
                            $dateCreated = $this->parseDateTimeOffset($invoice->DateUpdated);
                        }

                        $rows[] = [
                            'id'             => $id,
                            'customer_id'    => $customer_id,
                            'currency_id'    => $currency->id,
                            'description'    => $description,
                            'invoice_number' => 'INV-' . str_pad($id, 6, '0', STR_PAD_LEFT),
                            'source'         => $source,
                            'source_id'      => $invoice->CustomerApplicationId,
                            'amount'         => $amount,
                            'year'           => $dateCreated ? Carbon::parse($dateCreated)->year : now()->year,
                            'uuid'           => Str::uuid()->toString(),
                            'status'         => $status,
                            'created_at'     => $dateCreated ?? now(),
                            'updated_at'     => $this->parseDateTimeOffset($invoice->DateUpdated),
                        ];

                        $existingIds[$id] = true;

                    } catch (\Exception $e) {
                        $this->error('Error processing Invoice ID: ' . $id . ' — ' . $e->getMessage());
                    }
                }

                if (!empty($rows)) {
                    try {
                        Newinvoice::insertOrIgnore($rows);
                        $counter += count($rows);
                        $this->info('Inserted ' . count($rows) . ' invoices. Total so far: ' . $counter);
                    } catch (\Exception $e) {
                        $this->error('Bulk insert failed, falling back row-by-row: ' . $e->getMessage());
                        foreach ($rows as $row) {
                            try {
                                Newinvoice::insertOrIgnore([$row]);
                                $counter++;
                            } catch (\Exception $ex) {
                                $this->error('Failed Invoice ID ' . $row['id'] . ': ' . $ex->getMessage());
                            }
                        }
                    }
                }
            }, 'Id');

        $this->info('Total Imported Invoices: ' . $counter);
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
