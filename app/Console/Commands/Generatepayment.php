<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Applicationpayment;
class Generatepayment extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:generatepayment';

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
         $payments = Applicationpayment::with('applicationinvoice', 'currency', 'exchangeRate', 'paymentchannel')->get();
         $filename = 'payment_list_' . date('Y-m-d_His') . '.csv';
         $filepath = storage_path('app/' . $filename);
         $file = fopen($filepath, 'w');
         $headers = [
             'id',
             'invoice_id',
             'ExchangeRate',
             'Paymentchannel',
             'Currency',
             'Amount',
             'status',
         ];
         fputcsv($file, $headers);
         $row = [];
         foreach ($payments as $payment) {
             $invoice_id = $payment->applicationinvoice?->Id;
             $exchangeRate = $payment->exchangeRate?->Rate;
             $paymentchannel = $payment->paymentchannel?->Name;
             $currency = $payment->currency?->Name;
             $amount = $payment->Amount;
             $status = $payment->Status;
             $row = [
                 $payment->Id,
                 $invoice_id,
                 $exchangeRate,
                 $paymentchannel,
                 $currency,
                 $amount,
                 $status,
             ];
             fputcsv($file, $row);
         }
         fclose($file);
         $this->info("CSV file created successfully: {$filepath}");
         $this->info("Total records exported: " . $payments->count());
    }
}
