<?php

namespace App\Console\Commands;

use App\Models\NewInvoice;
use App\Models\NewPayment;
use App\Models\NewReceipt;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportReceipt extends Command
{
    protected $signature = 'app:import-receipt';

    protected $description = 'Import receipts from suspenses table';

    public function handle()
    {
        $this->info('Starting receipt import...');

        // Get all pending suspenses
        $suspenses = NewPayment::where('status', 'PENDING')->get();

        foreach ($suspenses as $suspense) {

            // Find latest invoice for customer
            $invoice = NewInvoice::where('customer_id', $suspense->customer_id)
                ->orderBy('id', 'desc')
                ->first();

            if (! $invoice) {
                $this->warn("No invoice found for customer ID: {$suspense->customer_id}");

                continue;
            }

            DB::connection('mysql2')->beginTransaction();

            try {

                // ============================
                // AUTO INCREMENT RECEIPT NUMBER
                // ============================
                $year = date('Y');
                $invoiceId = $invoice->id;

                // Get last receipt for this invoice
                $lastReceipt = NewReceipt::where('invoice_id', $invoiceId)
                    ->orderBy('id', 'desc')
                    ->first();

                $nextSeq = 1;

                if ($lastReceipt && preg_match('/REC-\d+-\d+-(\d+)/', $lastReceipt->receipt_number, $m)) {
                    $nextSeq = ((int) $m[1]) + 1;
                }

                $receiptNumber = "REC-{$year}-{$invoiceId}-{$nextSeq}";

                // ============================
                // INSERT RECEIPT
                // ============================
                $receipt = new NewReceipt;
                $receipt->customer_id = $suspense->customer_id;
                $receipt->currency_id = $suspense->currency_id;
                $receipt->suspense_id = $suspense->id;
                $receipt->invoice_id = $invoiceId;
                $receipt->exchangerate_id = 5; // Change if dynamic
                $receipt->receipt_number = $receiptNumber;
                $receipt->amount = $suspense->amount;
                $receipt->save();

                // Update suspense status
                $suspense->status = 'UTILIZED';
                $suspense->save();

                DB::connection('mysql2')->commit();

                $this->info("Receipt Created: {$receiptNumber}");

            } catch (\Exception $e) {
                DB::connection('mysql2')->rollBack();
                $this->error("Failed for suspense ID {$suspense->id} - ".$e->getMessage());
            }
        }

        $this->info('Receipt import completed!');
    }
}
