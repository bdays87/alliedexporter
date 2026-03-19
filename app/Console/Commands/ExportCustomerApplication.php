<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Customerapplication;
use App\Models\Newcustomerapplication;
use App\Models\Applicationinvoice;
use App\Models\RegisterCategory;
use Carbon\Carbon;

class ExportCustomerApplication extends Command
{
    protected $signature = 'app:exportcustomerapplication';
    protected $description = 'Post back customer application data to old MySQL system';

    public function handle()
    {
        $applications = Newcustomerapplication::with('customer','registertype','customerprofession')
            ->whereNotNull('customer_id')
            ->get();

        $bar = $this->output->createProgressBar($applications->count());
        $bar->start();

        foreach ($applications as $app) {
            $this->processApplication($app);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info('✅ Post back completed.');
    }

    private function processApplication($app)
    {
        $existing = Customerapplication::where('Id', $app->id)->first();

        // Get invoice for payment item and amount details
        $invoice = Applicationinvoice::where('CustomerApplicationId', $app->id)->first();

        // Check if invoice is paid
        $isPaid = false;
        $totalPaid = 0;
        $totalDue = 0;

        if ($invoice) {
            $totalDue = floatval($invoice->TotalDue ?? $invoice->AmountDue ?? 0);
            $totalPaid = $this->getTotalPaid($app->id);
            $isPaid = $totalPaid >= $totalDue;
        }

        // Map status based on application status from new system
        // APPROVED = fully approved (don't mix)
        // AWAITING = invoice paid, waiting for registration/account approval
        // PENDING = not paid yet
        $approvalStatus = 'PENDING';
        $registrarStatus = 0;
        $accountStatus = 0;
        $renewalStatusId = 3; // Default owing

        $cdpoints = 0;
        $placement = 0;

        // If status is APPROVED - keep as approved
        if ($app->status == 'APPROVED') {
            $approvalStatus = 'APPROVED';
            $renewalStatusId = $isPaid ? 1 : 3; // paid = 1, owing = 3
            $registrarStatus = 1;
            $accountStatus = 1;
            $cdpoints = 1;
            $placement = 1;
        }
        // If status is AWAITING - invoice is paid, waiting for registration or account approval
        elseif ($app->status == 'AWAITING') {
            $approvalStatus = 'AWAITING';
            $renewalStatusId = $isPaid ? 1 : 3; // paid = 1, owing = 3
            $registrarStatus = $app->registration ?? 0;
            $accountStatus = $app->accounts ?? 0;
           $cdpoints = 1;
                $placement = 1;
        }
        // If status is PENDING - check if invoice exists and is paid
        elseif ($app->status == 'PENDING') {
            // Check if invoice exists
            if (!$invoice) {
                // No invoice exists - PENDING OWING
                $approvalStatus = 'PENDING';
                $renewalStatusId = 3; // owing
                $registrarStatus = 0;
                $accountStatus = 0;
                $cdpoints = 0;
                $placement = 0;
            }
            // Check if invoice was already paid in old system
            elseif ($isPaid) {
                // Invoice is paid but waiting at Registration stage
                $approvalStatus = 'AWAITING';
                $renewalStatusId = 1; // paid
                $registrarStatus = 0; // waiting at registration
                $accountStatus = 0;   // not yet at accounts
                $cdpoints = 1;
                $placement = 1;
            } else {
                // Invoice exists but not paid - PENDING OWING
                $approvalStatus = 'PENDING';
                $renewalStatusId = 3; // owing
                $registrarStatus = 0;
                $accountStatus = 0;
                $cdpoints = 0;
                $placement = 0;
            }
        }


        // Determine ApplicationTypeId based on applicationtype_id from new system
        // 1 = New Registration, 2 = Renewal, 3 = Restoration


         $renewalCategoryId = 1;
           $applicationTypeId;
            if($app->applicationtype_id == 3){
               $renewalCategoryId = 4;
                $applicationTypeId = 2;
            }
            else{
               $renewalCategoryId = $this->getRenewalCategoryId($app->customer_id);
                $applicationTypeId = $app->applicationtype_id ?? 1;
            }




        // Get payment item ID from invoice if available
        $paymentItemId = $invoice?->PaymentItemId ?? ($applicationTypeId == 1 ? 45 : 46);

        // Calculate next renewal period
        $renewalPeriod = $app->year ?? date('Y');
        $currentYear = date('Y');
        $currentMonth = date('m');

        $nextRenewal = $renewalPeriod;
        if ($currentMonth >= 10 && $renewalPeriod == $currentYear) {
            $nextRenewal = $currentYear + 1;
        }

        $regid = RegisterCategory::where('name', $app->registertype->name)->first();

        // Get register category from customer profession
        $registerCategoryId = $regid->Id;

        // Default values based on C# service logic
        $balance = $invoice ? (floatval($invoice->TotalDue) - floatval($this->getTotalPaid($app->id))) : 0;
        $balance = $balance > 0 ? (string) $balance : "0";





        $this->info("Posting back ID: " . $app->id);

        if ($existing) {
            // UPDATE existing record
            $existing->CustomerId = $app->customer_id;
            $existing->CustomerProfessionId = $app->customerprofession_id;
            $existing->ApplicationTypeId = $applicationTypeId;
            $existing->RenewalCategoryId = $renewalCategoryId;
            $existing->RegisterCategoryId = $registerCategoryId;
            $existing->RenewalPeriod = $nextRenewal;
            $existing->PaymentItemId = $paymentItemId;
            $existing->RenewalStatusId = $renewalStatusId;
            $existing->PaymentMethodId = 1;
            $existing->balance = $balance;
            $existing->Cdpoints = $cdpoints;
            $existing->Placement = $placement;
            $existing->RenewalPeriod = $app->year;
            $existing->CertificateNumber = $app->certificate_number;
            $existing->ApprovalStatus = $approvalStatus;
            $existing->RegistrarStatus = $registrarStatus;
            $existing->AccountStatus = $accountStatus;
            $existing->DateUpdated = now();

            $existing->save();
        } else {
            // INSERT new record
            $new = new Customerapplication();

            $new->Id = $app->id;
            $new->CustomerId = $app->customer_id;
            $new->CustomerProfessionId = $app->customerprofession_id;
            $new->ApplicationTypeId = $applicationTypeId;
            $new->RenewalCategoryId = $renewalCategoryId;
            $new->RegisterCategoryId = $registerCategoryId;
            $new->RenewalPeriod = $nextRenewal;
            $new->PaymentItemId = $paymentItemId;
            $new->RenewalStatusId = $renewalStatusId;
            $new->PaymentMethodId = 1;
            $new->balance = $balance;
            $new->Cdpoints = $cdpoints;
            $new->Placement = $placement;
            $new->RenewalPeriod = $app->year;
            $new->CertificateNumber = $app->certificate_number;
            $new->ApprovalStatus = $approvalStatus;
            $new->RegistrarStatus = $registrarStatus;
            $new->AccountStatus = $accountStatus;
            $new->DateCreated = $this->formatDate($app->created_at);
            $new->DateUpdated = $this->formatDate($app->updated_at);

            $new->save();
        }
    }

    private function getTotalPaid($applicationId)
    {
        $invoice = Applicationinvoice::where('CustomerApplicationId', $applicationId)->first();
        if (!$invoice) {
            return 0;
        }

        $payments = \App\Models\Applicationpayment::where('ApplicationInvoiceId', $invoice->Id)->get();
        return $payments->sum(function ($payment) {
            return floatval($payment->BaseAmount ?? $payment->Amount ?? 0);
        });
    }

    private function getRenewalCategoryId($customerId)
    {
        $customer = \App\Models\Customer::find($customerId);
        if (!$customer || !$customer->Dob) {
            return 1; // Default category
        }

        try {
            $dob = Carbon::parse($customer->Dob);
            $age = Carbon::now()->diffInYears($dob);

            // Match age to renewal category
            // Based on C# logic: 60-64, 65-74, Over 75
            if ($age >= 60 && $age <= 64) {
                return 5; // 60 to 64 Years
            } elseif ($age >= 65 && $age <= 74) {
                return 6; // 65 to 74 years
            } elseif ($age >= 75) {
                return 7; // Over 75 years
            }

            return 1; // Default
        } catch (\Exception $e) {
            return 1;
        }
    }

    private function formatDate($date)
    {
        if (!$date) {
            return now();
        }

        try {
            return Carbon::parse($date)->format('Y-m-d H:i:s.u');
        } catch (\Exception $e) {
            return now();
        }
    }
}
