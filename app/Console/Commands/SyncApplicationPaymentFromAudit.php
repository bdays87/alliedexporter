<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncApplicationPaymentFromAudit extends Command
{
    protected $signature = 'sync:applicationpayment
                            {--year=2026 : The renewal period year to filter by}
                            {--current-year=2026 : The actual current year (used to decide status filter)}';

    protected $description = 'Sync applicationpayment from auditentries. Only payments linked to qualifying invoices/applications.';

    public function handle(): int
    {
        $year = (int) $this->option('year');
        $currentYear = (int) $this->option('current-year');

        $allowedStatuses = ($year < $currentYear)
            ? ['APPROVED']
            : ['AWAITING', 'APPROVED'];

        $this->info("Syncing ApplicationPayment for year {$year} (apps: ".implode(', ', $allowedStatuses).')...');

        $audits = DB::connection('mysql')
            ->table('auditentries')
            ->where('EntityName', 'ApplicationPayment')
            ->whereRaw('YEAR(TIMESTAMP) IN (?, ?)', [$year - 1, $year])
            ->orderBy('TIMESTAMP', 'asc')
            ->get();

        $this->info("Found {$audits->count()} audit records");

        // Group all changes per payment ID
        $paymentChanges = [];

        foreach ($audits as $audit) {
            $data = json_decode($audit->Changes, true);

            if (! $data || ! isset($data['Id'])) {
                continue;
            }

            $paymentChanges[$data['Id']][] = [
                'data' => $data,
                'timestamp' => $audit->Timestamp ?? $audit->timestamp ?? now(),
            ];
        }

        $this->info('Found '.count($paymentChanges).' unique payments');

        $inserted = 0;
        $updated = 0;
        $skippedNoInvoice = 0;

        foreach ($paymentChanges as $paymentId => $changes) {
            $finalData = end($changes)['data'];

            if (! isset($finalData['ApplicationInvoiceId'])) {
                $skippedNoInvoice++;

                continue;
            }

            // Verify the invoice exists
            $invoice = DB::connection('mysql')
                ->table('applicationinvoices')
                ->where('Id', $finalData['ApplicationInvoiceId'])
                ->first();

            if (! $invoice) {
                $skippedNoInvoice++;

                continue;
            }

            // Verify the linked application belongs to this renewal period
            // and has an allowed status
            $application = DB::connection('mysql')
                ->table('customerapplication')
                ->where('Id', $invoice->CustomerApplicationId)
                ->where('RenewalPeriod', $year)
                ->whereIn('ApprovalStatus', $allowedStatuses)
                ->first();

            if (! $application) {
                $skippedNoInvoice++;

                continue;
            }

            $this->info("Payment {$paymentId}: invoice={$finalData['ApplicationInvoiceId']}, app={$invoice->CustomerApplicationId}");

            try {
                $dateCreated = $this->parseDateTime($finalData['DateCreated'] ?? null);
                $dateUpdated = $this->parseDateTime($finalData['DateUpdated'] ?? null);
                $dateDeleted = $this->parseDateTime($finalData['DateDeleted'] ?? null);

                $exists = DB::connection('mysql')
                    ->table('applicationpayments')
                    ->where('Id', $paymentId)
                    ->exists();

                $record = [
                    'ApplicationInvoiceId' => $finalData['ApplicationInvoiceId'],
                    'ExchangeRateId' => $finalData['ExchangeRateId'] ?? null,
                    'PaymentMethodId' => $finalData['PaymentMethodId'] ?? null,
                    'PaymentChannelId' => $finalData['PaymentChannelId'] ?? null,
                    'CurrencyId' => $finalData['CurrencyId'] ?? null,
                    'Amount' => $finalData['Amount'] ?? null,
                    'pollUrl' => $finalData['pollUrl'] ?? null,
                    'referencenumber' => $finalData['referencenumber'] ?? null,
                    'pop_url' => $finalData['pop_url'] ?? null,
                    'phonenumber' => $finalData['phonenumber'] ?? null,
                    'BaseCurrencyId' => $finalData['BaseCurrencyId'] ?? null,
                    'BaseAmount' => $finalData['BaseAmount'] ?? null,
                    'DateUpdated' => $dateUpdated,
                    'DateDeleted' => $dateDeleted,
                ];

                if ($exists) {
                    DB::connection('mysql')->table('applicationpayments')
                        ->where('Id', $paymentId)
                        ->update($record);
                    $this->info('  → Updated');
                    $updated++;
                } else {
                    DB::connection('mysql')->table('applicationpayments')
                        ->insert(array_merge($record, [
                            'Id' => $finalData['Id'],
                            'DateCreated' => $dateCreated,
                        ]));
                    $this->info('  → Inserted');
                    $inserted++;
                }
            } catch (\Exception $e) {
                $this->error("Failed payment {$paymentId}: ".$e->getMessage());
            }
        }

        $this->info("Done. Inserted: {$inserted}, Updated: {$updated}, Skipped (no invoice/app): {$skippedNoInvoice}");

        return Command::SUCCESS;
    }

    protected function parseDateTime(?string $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        try {
            return (new \DateTime($value))->format('Y-m-d H:i:s');
        } catch (\Exception) {
            return null;
        }
    }
}
