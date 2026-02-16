<?php

namespace App\Console\Commands;

use App\Models\Applicationinvoice;
use App\Models\Newcustomerapplication;
use Carbon\Carbon;
use Illuminate\Console\Command;

class Importinvoices extends Command
{
    /**
     * The name and signature of the console command.
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
        $invoices = Applicationinvoice::with('payments', 'customerapplication', 'currency')->whereIn('PaymentItemId', [33, 46, 45])->get();
        $counter = 0;
        $this->info('Total Invoices: ' . $invoices->count());
        foreach ($invoices as $invoice) {
            $id = $invoice->Id;
            $customer_id = $invoice->customerapplication->CustomerId;

            $checkcustomer = \App\Models\Newcustomer::where("id", $customer_id)->first();
            if (!$checkcustomer) {
                $this->error('Customer not found for Invoice ID: ' . $invoice->Id . ' and Customer ID: ' . $customer_id);
                continue;
            }
            $checkcurrency = \App\Models\Newcurrency::where("name", $invoice->currency->Name)->first();
            if (!$checkcurrency) {
                $this->error('Currency not found for Invoice ID: ' . $invoice->Id . ' and Currency Name: ' . $invoice->currency->Name);
                continue;
            }
            $currency_id = $checkcurrency->id;
            $description = $invoice->PaymentItemId == 33 ? "Renewal" : ($invoice->PaymentItemId == 46 ? "Renewal" : ($invoice->PaymentItemId == 45 ? "New" : "OTHER FEE"));

            $customerapplication = Newcustomerapplication::with('customerprofession')->where("id", $invoice->CustomerApplicationId)->first();

            $invoice_number = "INV-" . rand(100000, 999999);
            $source = "customerapplication";
            $source_id = $invoice->CustomerApplicationId;
            $amount = $invoice->TotalDue;
            $total =  $invoice->payments->sum('Amount');
            $status = "AWAITING";
            $year = Carbon::parse($invoice->DateCreated)->year;
            if ($amount == $total) {
                $status = "PAID";
            } else {
                $customerapplication->status = "AWAITING";
                $customerapplication->save();
                $customerapplication->customerprofession->status = "AWAITING_APP";
                $customerapplication->customerprofession->save();
            }
            $newinvoice = new \App\Models\Newinvoice();
            $newinvoice->id = $id;
            $newinvoice->customer_id = $customer_id;
            $newinvoice->currency_id = $currency_id;
            $newinvoice->description = $description;
            $newinvoice->invoice_number = $invoice_number;
            $newinvoice->source = $source;
            $newinvoice->source_id = $source_id;
            $newinvoice->amount = $amount;
            $newinvoice->year = $year;
            $newinvoice->uuid = \Str::uuid();
            $newinvoice->status = $status;
            $newinvoice->save();
            $counter++;
            if ($counter % 100 == 0) {
                $this->info('Imported Invoices: ' . $counter);
            }
        }
    }
}
