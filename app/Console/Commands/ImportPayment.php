<?php

namespace App\Console\Commands;

use App\Models\Applicationpayment;
use App\Models\Newcurrency;
use App\Models\NewPayment;
use Illuminate\Console\Command;
use Str;
use Carbon\Carbon;
class ImportPayment extends Command
{
    protected $signature = 'app:import-payment';

    protected $description = 'Import payments into suspenses table';

    public function handle()
    {
        $payments = Applicationpayment::with(['applicationinvoice.customerapplication', 'paymentchannel', 'currency'])->get();

        $this->info('Total Payments: '.$payments->count());
        $counter = 0;

        foreach ($payments as $payment) {

            if (! $payment->applicationinvoice) {
                $this->error('Invoice missing for Payment ID: '.$payment->Id);

                continue;
            }

            // Get customer from invoice
            $customer_id = $payment->applicationinvoice->customerapplication->CustomerId ?? null;
            if (! $customer_id) {
                $this->error('Customer missing for Payment ID: '.$payment->Id);

                continue;
            }

            // Currency mapping
            $currency = Newcurrency::where('name', $payment->currency->Name ?? null)->first();
            if (! $currency) {
                $this->error('Currency not found for Payment ID: '.$payment->Id);

                continue;
            }

            // Convert amount (LONGTEXT → decimal)
            $amount = floatval($payment->Amount);
            // RenewalStatusId
            $paymentstatus = 'PENDING';
            // $this->info('check Renewalstatus: '.$payment->applicationinvoice->customerapplication->RenewalStatusId);
            // if ($payment->applicationinvoice->customerapplication->RenewalStatusId == 1) {
            //     $paymentstatus = 'UTILIZED';
            // } else {
            //     $paymentstatus = 'PENDING';
            // }
            // Create suspense record
            $suspense = new NewPayment;
            $suspense->id = $payment->Id;
            $suspense->customer_id = $customer_id;
            $suspense->currency_id = $currency->id;
            $suspense->uuid = Str::uuid();
            $suspense->source = $payment->paymentchannel->Name ?? 'Cash';
            $suspense->source_id = $payment->Id;
            $suspense->amount = $amount;
            $suspense->createdby=1;
            $suspense->status = $paymentstatus; // UTILIZEDS


          // Handle C# DateTimeOffset format and convert to Laravel timestamp
            $dateCreated = $this->parseDateTimeOffset($payment->DateCreated);
            // If DateCreated is DateTime.MinValue, try to use DateUpdated instead
            if (! $dateCreated) {
                $dateCreated = $this->parseDateTimeOffset($payment->DateUpdated);
            }
            $suspense->created_at = $dateCreated ?? now();
            $suspense->updated_at = $this->parseDateTimeOffset($payment->DateUpdated);


            $suspense->save();

            $counter++;
            if ($counter % 100 == 0) {
                $this->info('Imported Payments: '.$counter);
            }
        }

        $this->info("✅ Import completed. Total imported: $counter");
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
