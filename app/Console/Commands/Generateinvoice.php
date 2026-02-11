<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Applicationinvoice;
use App\Models\Applicationpayment;
use App\Models\Currency;
use App\Models\Exchangerate;
use App\Models\Paymentmethod;
class Generateinvoice extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:generateinvoice';

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
        $invoices = Applicationinvoice::with('customerapplication.customer', 'currency', 'payment_type', 'payments', 'payments.currency', 'payments.exchangeRate', 'payments.paymentmethod')->get();
       
        $filename = 'invoice_list_' . date('Y-m-d_His') . '.csv';
        $filepath = storage_path('app/' . $filename);

        $file = fopen($filepath, 'w');
        $headers = [
            'id',
            'customer',
            'description',
            'source',
            'source_id',
            'year',
            'currency',
            'amount',
            'status',
           
    ];

    fputcsv($file, $headers);
    $row = [];
    foreach ($invoices as $invoice) {
       $customer = $invoice->customerapplication?->customer?->Prefix . $invoice->customerapplication?->customer?->RegistrationNumber;
       $currency = $invoice->currency->Name;
       $description = $invoice->payment_type?->Name;
       $source = 'customerapplication';
       $source_id = $invoice->customerapplication?->Id;
       $year = $invoice->customerapplication?->RenewalPeriod;
       $amount = $invoice?->TotalDue;
       $status = "PENDING";
       $row = [
        $invoice->Id,
        $customer,
        $description,
        $source,
        $source_id,
        $year,
        $currency,
        $amount,
        $status,
    ];
    fputcsv($file, $row);
    }
    fclose($file);
    $this->info("CSV file created successfully: {$filepath}");
    $this->info("Total records exported: " . $invoices->count());
}
}
