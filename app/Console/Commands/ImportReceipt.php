<?php

namespace App\Console\Commands;

use App\Models\Newinvoice;
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
        $year      = date('Y');
        $chunkSize = 200;

        // Pre-load existing suspense_ids that already have receipts — one query
        $existingSuspenseIds = NewReceipt::pluck('suspense_id')->flip()->all();

        // Only load invoices for customers that actually have PENDING payments — avoids loading entire invoices table
        $pendingCustomerIds = NewPayment::where('status', 'PENDING')
            ->pluck('customer_id')
            ->unique();

        $latestInvoiceByCustomer = Newinvoice::whereIn('customer_id', $pendingCustomerIds)
            ->orderByDesc('id')
            ->get()
            ->keyBy('customer_id');

        // Pre-load receipt sequences per invoice_id in memory — no per-row DB query needed
        $receiptSeqs = NewReceipt::get(['invoice_id', 'receipt_number'])
            ->groupBy('invoice_id')
            ->map(function ($receipts) {
                $max = 0;
                foreach ($receipts as $r) {
                    if (preg_match('/REC-\d+-\d+-(\d+)/', $r->receipt_number, $m)) {
                        $max = max($max, (int) $m[1]);
                    }
                }
                return $max;
            })
            ->all();

        // Detect which DB connection the models use for correct transaction scoping
        $connection = (new NewReceipt)->getConnectionName();

        $counter = 0;

        NewPayment::where('status', 'PENDING')
            ->chunkById($chunkSize, function ($suspenses) use (
                &$counter, &$existingSuspenseIds, &$receiptSeqs,
                $latestInvoiceByCustomer, $year, $connection
            ) {
                foreach ($suspenses as $suspense) {
                    try {
                        if (isset($existingSuspenseIds[$suspense->id])) {
                            continue;
                        }

                        if (!$suspense->customer_id) {
                            $this->error('Missing customer_id on suspense ID: ' . $suspense->id);
                            continue;
                        }

                        if (!$suspense->currency_id) {
                            $this->error('Missing currency_id on suspense ID: ' . $suspense->id);
                            continue;
                        }

                        $invoice = $latestInvoiceByCustomer->get($suspense->customer_id);

                        if (!$invoice) {
                            $this->warn('No invoice found for customer ID: ' . $suspense->customer_id . ' (suspense ID: ' . $suspense->id . ')');
                            continue;
                        }

                        $invoiceId = $invoice->id;

                        // Reserve sequence number — only commit to memory after successful save
                        $nextSeq       = ($receiptSeqs[$invoiceId] ?? 0) + 1;
                        $receiptNumber = "REC-{$year}-{$invoiceId}-{$nextSeq}";

                        DB::connection($connection)->beginTransaction();

                        $receipt                  = new NewReceipt;
                        $receipt->customer_id     = $suspense->customer_id;
                        $receipt->currency_id     = $suspense->currency_id;
                        $receipt->suspense_id     = $suspense->id;
                        $receipt->invoice_id      = $invoiceId;
                        $receipt->exchangerate_id = 5;
                        $receipt->receipt_number  = $receiptNumber;
                        $receipt->amount          = $suspense->amount;
                        $receipt->save();

                        $suspense->status = 'UTILIZED';
                        $suspense->save();

                        DB::connection($connection)->commit();

                        // Only update in-memory state after successful commit
                        $receiptSeqs[$invoiceId]            = $nextSeq;
                        $existingSuspenseIds[$suspense->id] = true;
                        $counter++;
                        $this->info("Receipt Created: {$receiptNumber}");

                    } catch (\Exception $e) {
                        try {
                            DB::connection($connection)->rollBack();
                        } catch (\Exception) {
                            // Transaction may not have started yet (e.g. error was before beginTransaction)
                        }
                        $this->error('Failed for suspense ID ' . $suspense->id . ' — ' . $e->getMessage());
                    }
                }
            });

        $this->info('Receipt import completed. Total created: ' . $counter);
    }
}
