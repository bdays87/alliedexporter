<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncApplicationInvoiceFromAudit extends Command
{
    protected $signature = 'sync:applicationinvoice {--year=2026 : The year to filter by}';
    protected $description = 'Sync applicationinvoice from auditentries - finds final status from audit trail';

    public function handle(): int
    {
        $year = (int) $this->option('year');

        $this->info("Syncing ApplicationInvoice for year {$year}...");

        // Get all audit entries ordered by timestamp
        // Include both current year and previous year (Oct 2025 for 2026 renewal)
        $audits = DB::connection('mysql')
            ->table('auditentries')
            ->where('EntityName', 'Applicationinvoice')
            ->whereRaw('YEAR(TIMESTAMP) IN (?, ?)', [$year - 1, $year])
            ->orderBy('TIMESTAMP', 'asc')
            ->get();

        $this->info("Found {$audits->count()} audit records");

        // Group by invoice ID to track all changes
        $invoiceChanges = [];

        foreach ($audits as $audit) {
            $data = json_decode($audit->Changes, true);

            if (!$data || !isset($data['Id'])) {
                continue;
            }

            $invoiceId = $data['Id'];

            // Track each change for this invoice
            $invoiceChanges[$invoiceId][] = [
                'data' => $data,
                'timestamp' => $audit->timestamp ?? $audit->Timestamp ?? now(),
            ];
        }

        $this->info("Found " . count($invoiceChanges) . " unique invoices");

        $inserted = 0;
        $updated = 0;
        $skippedNoApp = 0;

        foreach ($invoiceChanges as $invoiceId => $changes) {
            // Get the final status (last entry)
            $finalStatus = end($changes);
            $data = $finalStatus['data'];

            // Only process if CustomerApplicationId exists and links to a 2026 application
            if (!isset($data['CustomerApplicationId'])) {
                $skippedNoApp++;
                continue;
            }

            // Verify the application exists and has 2026 period
            $application = DB::connection('mysql')
                ->table('customerapplication')
                ->where('Id', $data['CustomerApplicationId'])
                ->where('RenewalPeriod', $year)
                ->first();

            if (!$application) {
                $skippedNoApp++;
                continue;
            }

            $this->info("Invoice {$invoiceId}: Final status for Application {$data['CustomerApplicationId']}");

            // Check if already exists
            $exists = DB::connection('mysql')
                ->table('applicationinvoices')
                ->where('Id', $invoiceId)
                ->exists();

            try {
                // Parse datetime values to MySQL format
                $dateCreated = $this->parseDateTime($data['DateCreated'] ?? null);
                $dateUpdated = $this->parseDateTime($data['DateUpdated'] ?? null);
                $dateDeleted = $this->parseDateTime($data['DateDeleted'] ?? null);

                if ($exists) {
                    // Update with the final status
                    DB::connection('mysql')->table('applicationinvoices')
                        ->where('Id', $invoiceId)
                        ->update([
                            'CustomerApplicationId' => $data['CustomerApplicationId'],
                            'PaymentItemId' => $data['PaymentItemId'] ?? null,
                            'RenewalTireId' => $data['RenewalTireId'] ?? null,
                            'RenewalCategoryId' => $data['RenewalCategoryId'] ?? null,
                            'CurrencyId' => $data['CurrencyId'] ?? null,
                            'RenewalPenaltyId' => $data['RenewalPenaltyId'] ?? null,
                            'AmountDue' => $data['AmountDue'] ?? null,
                            'Penalty' => $data['Penalty'] ?? null,
                            'TotalDue' => $data['TotalDue'] ?? null,
                            'DateUpdated' => $dateUpdated,
                            'DateDeleted' => $dateDeleted,
                        ]);

                    $this->info("  → Updated");
                    $updated++;
                } else {
                    DB::connection('mysql')->table('applicationinvoices')->insert([
                        'Id' => $data['Id'],
                        'CustomerApplicationId' => $data['CustomerApplicationId'],
                        'PaymentItemId' => $data['PaymentItemId'] ?? null,
                        'RenewalTireId' => $data['RenewalTireId'] ?? null,
                        'RenewalCategoryId' => $data['RenewalCategoryId'] ?? null,
                        'CurrencyId' => $data['CurrencyId'] ?? null,
                        'RenewalPenaltyId' => $data['RenewalPenaltyId'] ?? null,
                        'AmountDue' => $data['AmountDue'] ?? null,
                        'Penalty' => $data['Penalty'] ?? null,
                        'TotalDue' => $data['TotalDue'] ?? null,
                        'DateCreated' => $dateCreated,
                        'DateUpdated' => $dateUpdated,
                        'DateDeleted' => $dateDeleted,
                    ]);

                    $this->info("  → Inserted");
                    $inserted++;
                }

            } catch (\Exception $e) {
                $this->error("Failed ID {$invoiceId} - " . $e->getMessage());
            }
        }

        $this->info("Done syncing ApplicationInvoice. Inserted: {$inserted}, Updated: {$updated}, Skipped (no app): {$skippedNoApp}");

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
