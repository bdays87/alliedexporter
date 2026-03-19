<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncApplicationPaymentFromAudit extends Command
{
    protected $signature = 'sync:applicationpayment {--year=2026 : The year to filter by}';
    protected $description = 'Sync applicationpayment from auditentries - finds final status from audit trail';

    public function handle(): int
    {
        $year = (int) $this->option('year');

        $this->info("Syncing ApplicationPayment for year {$year}...");

        // Get all audit entries ordered by timestamp
        // Include both current year and previous year (Oct 2025 for 2026 renewal)
        $audits = DB::connection('mysql')
            ->table('auditentries')
            ->where('EntityName', 'ApplicationPayment')
            ->whereRaw('YEAR(TIMESTAMP) IN (?, ?)', [$year - 1, $year])
            ->orderBy('TIMESTAMP', 'asc')
            ->get();

        $this->info("Found {$audits->count()} audit records");

        // Group by payment ID to track all changes
        $paymentChanges = [];

        foreach ($audits as $audit) {
            $data = json_decode($audit->Changes, true);

            if (!$data || !isset($data['Id'])) {
                continue;
            }

            $paymentId = $data['Id'];

            // Track each change for this payment
            $paymentChanges[$paymentId][] = [
                'data' => $data,
                'timestamp' => $audit->timestamp ?? $audit->Timestamp ?? now(),
            ];
        }

        $this->info("Found " . count($paymentChanges) . " unique payments");

        $inserted = 0;
        $updated = 0;
        $skippedNoInvoice = 0;

        foreach ($paymentChanges as $paymentId => $changes) {
            // Get the final status (last entry)
            $finalStatus = end($changes);
            $data = $finalStatus['data'];

            // Only process if ApplicationInvoiceId exists
            if (!isset($data['ApplicationInvoiceId'])) {
                $skippedNoInvoice++;
                continue;
            }

            // Verify the invoice exists
            $invoice = DB::connection('mysql')
                ->table('applicationinvoices')
                ->where('Id', $data['ApplicationInvoiceId'])
                ->first();

            if (!$invoice) {
                $skippedNoInvoice++;
                continue;
            }

            // Verify the application has 2026 period
            $application = DB::connection('mysql')
                ->table('customerapplication')
                ->where('Id', $invoice->CustomerApplicationId)
                ->where('RenewalPeriod', $year)
                ->first();

            if (!$application) {
                $skippedNoInvoice++;
                continue;
            }

            $this->info("Payment {$paymentId}: Final status for Invoice {$data['ApplicationInvoiceId']} -> Application {$invoice->CustomerApplicationId}");

            // Check if already exists
            $exists = DB::connection('mysql')
                ->table('applicationpayments')
                ->where('Id', $paymentId)
                ->exists();

            try {
                // Parse datetime values to MySQL format
                $dateCreated = $this->parseDateTime($data['DateCreated'] ?? null);
                $dateUpdated = $this->parseDateTime($data['DateUpdated'] ?? null);
                $dateDeleted = $this->parseDateTime($data['DateDeleted'] ?? null);

                if ($exists) {
                    // Update with the final status
                    DB::connection('mysql')->table('applicationpayments')
                        ->where('Id', $paymentId)
                        ->update([
                            'ApplicationInvoiceId' => $data['ApplicationInvoiceId'],
                            'ExchangeRateId' => $data['ExchangeRateId'] ?? null,
                            'PaymentMethodId' => $data['PaymentMethodId'] ?? null,
                            'PaymentChannelId' => $data['PaymentChannelId'] ?? null,
                            'CurrencyId' => $data['CurrencyId'] ?? null,
                            'Amount' => $data['Amount'] ?? null,
                            'pollUrl' => $data['pollUrl'] ?? null,
                            'referencenumber' => $data['referencenumber'] ?? null,
                            'pop_url' => $data['pop_url'] ?? null,
                            'phonenumber' => $data['phonenumber'] ?? null,
                            'BaseCurrencyId' => $data['BaseCurrencyId'] ?? null,
                            'BaseAmount' => $data['BaseAmount'] ?? null,
                            'DateUpdated' => $dateUpdated,
                            'DateDeleted' => $dateDeleted,
                        ]);

                    $this->info("  → Updated");
                    $updated++;
                } else {
                    DB::connection('mysql')->table('applicationpayments')->insert([
                        'Id' => $data['Id'],
                        'ApplicationInvoiceId' => $data['ApplicationInvoiceId'],
                        'ExchangeRateId' => $data['ExchangeRateId'] ?? null,
                        'PaymentMethodId' => $data['PaymentMethodId'] ?? null,
                        'PaymentChannelId' => $data['PaymentChannelId'] ?? null,
                        'CurrencyId' => $data['CurrencyId'] ?? null,
                        'Amount' => $data['Amount'] ?? null,
                        'pollUrl' => $data['pollUrl'] ?? null,
                        'referencenumber' => $data['referencenumber'] ?? null,
                        'pop_url' => $data['pop_url'] ?? null,
                        'phonenumber' => $data['phonenumber'] ?? null,
                        'BaseCurrencyId' => $data['BaseCurrencyId'] ?? null,
                        'BaseAmount' => $data['BaseAmount'] ?? null,
                        'DateCreated' => $dateCreated,
                        'DateUpdated' => $dateUpdated,
                        'DateDeleted' => $dateDeleted,
                    ]);

                    $this->info("  → Inserted");
                    $inserted++;
                }

            } catch (\Exception $e) {
                $this->error("Failed ID {$paymentId} - " . $e->getMessage());
            }
        }

        $this->info("Done syncing ApplicationPayment. Inserted: {$inserted}, Updated: {$updated}, Skipped (no invoice): {$skippedNoInvoice}");

        return Command::SUCCESS;
    }

    /**
     * Parse datetime string to MySQL format
     */
    protected function parseDateTime(?string $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        try {
            // Handle ISO 8601 format with timezone
            $date = new \DateTime($value);
            return $date->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            // If parsing fails, return null
            return null;
        }
    }
}
