<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncApplicationInvoiceFromAudit extends Command
{
    protected $signature = 'sync:applicationinvoice
                            {--year=2026 : The renewal period year to filter by}
                            {--current-year=2026 : The actual current year (used to decide status filter)}';

    protected $description = 'Sync applicationinvoice from auditentries. One invoice per application unless split-currency (ZWG+USD). Past years: APPROVED apps only. Current year: AWAITING+APPROVED.';

    public function handle(): int
    {
        $year = (int) $this->option('year');
        $currentYear = (int) $this->option('current-year');

        $allowedStatuses = ($year < $currentYear)
            ? ['APPROVED']
            : ['AWAITING', 'APPROVED'];

        $this->info("Syncing ApplicationInvoice for year {$year} (apps: ".implode(', ', $allowedStatuses).')...');

        $audits = DB::connection('mysql')
            ->table('auditentries')
            ->where('EntityName', 'Applicationinvoice')
            ->whereRaw('YEAR(TIMESTAMP) IN (?, ?)', [$year - 1, $year])
            ->orderBy('TIMESTAMP', 'asc')
            ->get();

        $this->info("Found {$audits->count()} audit records");

        // Group all changes per invoice ID
        $invoiceChanges = [];

        foreach ($audits as $audit) {
            $data = json_decode($audit->Changes, true);

            if (! $data || ! isset($data['Id'])) {
                continue;
            }

            $invoiceChanges[$data['Id']][] = [
                'data' => $data,
                'timestamp' => $audit->Timestamp ?? $audit->timestamp ?? now(),
            ];
        }

        $this->info('Found '.count($invoiceChanges).' unique invoices');

        // Track invoices per application to enforce one-per-app rule
        // Exception: split payments (different currencies) allow one invoice per currency
        // appId => [ currencyId => invoiceId ]
        $appCurrencyMap = [];

        $inserted = 0;
        $updated = 0;
        $skippedNoApp = 0;
        $skippedDupe = 0;

        foreach ($invoiceChanges as $invoiceId => $changes) {
            $finalData = end($changes)['data'];

            if (! isset($finalData['CustomerApplicationId'])) {
                $skippedNoApp++;

                continue;
            }

            $appId = $finalData['CustomerApplicationId'];

            // Verify the application exists, belongs to this renewal period,
            // and has an allowed status
            $application = DB::connection('mysql')
                ->table('customerapplication')
                ->where('Id', $appId)
                ->where('RenewalPeriod', $year)
                ->whereIn('ApprovalStatus', $allowedStatuses)
                ->first();

            if (! $application) {
                $skippedNoApp++;

                continue;
            }

            $currencyId = $finalData['CurrencyId'] ?? null;

            // Enforce one invoice per application per currency
            // (split ZWG/USD payments legitimately produce two invoices)
            if (isset($appCurrencyMap[$appId][$currencyId])) {
                $existingInvoiceId = $appCurrencyMap[$appId][$currencyId];
                $this->warn("  Duplicate invoice {$invoiceId} for app {$appId} / currency {$currencyId} – already have {$existingInvoiceId}, skipping");
                $skippedDupe++;

                continue;
            }

            $appCurrencyMap[$appId][$currencyId] = $invoiceId;

            $this->info("Invoice {$invoiceId}: app={$appId}, currency={$currencyId}");

            try {
                $dateCreated = $this->parseDateTime($finalData['DateCreated'] ?? null);
                $dateUpdated = $this->parseDateTime($finalData['DateUpdated'] ?? null);
                $dateDeleted = $this->parseDateTime($finalData['DateDeleted'] ?? null);

                $exists = DB::connection('mysql')
                    ->table('applicationinvoices')
                    ->where('Id', $invoiceId)
                    ->exists();

                $record = [
                    'CustomerApplicationId' => $appId,
                    'PaymentItemId' => $finalData['PaymentItemId'] ?? null,
                    'RenewalTireId' => $finalData['RenewalTireId'] ?? null,
                    'RenewalCategoryId' => $finalData['RenewalCategoryId'] ?? null,
                    'CurrencyId' => $currencyId,
                    'RenewalPenaltyId' => $finalData['RenewalPenaltyId'] ?? null,
                    'AmountDue' => $finalData['AmountDue'] ?? null,
                    'Penalty' => $finalData['Penalty'] ?? null,
                    'TotalDue' => $finalData['TotalDue'] ?? null,
                    'DateUpdated' => $dateUpdated,
                    'DateDeleted' => $dateDeleted,
                ];

                if ($exists) {
                    DB::connection('mysql')->table('applicationinvoices')
                        ->where('Id', $invoiceId)
                        ->update($record);
                    $this->info('  → Updated');
                    $updated++;
                } else {
                    DB::connection('mysql')->table('applicationinvoices')
                        ->insert(array_merge($record, [
                            'Id' => $finalData['Id'],
                            'DateCreated' => $dateCreated,
                        ]));
                    $this->info('  → Inserted');
                    $inserted++;
                }
            } catch (\Exception $e) {
                $this->error("Failed invoice {$invoiceId}: ".$e->getMessage());
            }
        }

        $this->info("Done. Inserted: {$inserted}, Updated: {$updated}, Skipped (no app): {$skippedNoApp}, Skipped (duplicate): {$skippedDupe}");

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
