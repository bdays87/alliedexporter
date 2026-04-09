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

        $this->info("Syncing ApplicationInvoice for year {$year} (apps: " . implode(', ', $allowedStatuses) . ')...');

        $audits = DB::connection('mysql')
            ->table('auditentries')
            ->where('EntityName', 'Applicationinvoice')
            ->whereRaw('YEAR(TIMESTAMP) IN (?, ?)', [$year - 1, $year])
            ->orderBy('TIMESTAMP', 'asc')
            ->get();

        $this->info("Found {$audits->count()} audit records");

        // Replay audit history to get the final state of each invoice
        $invoiceChanges = [];

        foreach ($audits as $audit) {
            $data = json_decode($audit->Changes, true);

            if (! $data || ! isset($data['Id'])) {
                continue;
            }

            $invoiceChanges[$data['Id']][] = [
                'data' => $data,
                'timestamp' => $audit->Timestamp ?? now(),
            ];
        }

        $this->info('Found ' . count($invoiceChanges) . ' unique invoices in audit');

        // Load the set of valid application IDs for this year+status from the DB.
        // These were already synced by SyncCustomerApplicationFromAudit.
        $validAppIds = DB::connection('mysql')
            ->table('customerapplication')
            ->where('RenewalPeriod', $year)
            ->whereIn('ApprovalStatus', $allowedStatuses)
            ->pluck('Id')
            ->flip() // use as a hash-set for O(1) lookup
            ->all();

        // Build a map of what invoices already exist in the DB per app+currency
        // so we can enforce one-invoice-per-app-per-currency across re-runs.
        // appId => [ currencyId => invoiceId ]
        $appCurrencyMap = DB::connection('mysql')
            ->table('applicationinvoices')
            ->whereIn('CustomerApplicationId', array_keys($validAppIds))
            ->get(['Id', 'CustomerApplicationId', 'CurrencyId'])
            ->groupBy('CustomerApplicationId')
            ->map(fn ($rows) => $rows->pluck('Id', 'CurrencyId')->all())
            ->all();

        $inserted = 0;
        $updated = 0;
        $skippedNoApp = 0;
        $skippedDupe = 0;

        foreach ($invoiceChanges as $invoiceId => $changes) {
            $finalData = end($changes)['data'];

            $appId = $finalData['CustomerApplicationId'] ?? null;

            if (! $appId) {
                $skippedNoApp++;

                continue;
            }

            // Application must exist in DB with the correct year + allowed status
            if (! isset($validAppIds[$appId])) {
                $skippedNoApp++;

                continue;
            }

            $currencyId = $finalData['CurrencyId'] ?? null;

            // Enforce one invoice per application per currency.
            // Split ZWG/USD payments legitimately produce two invoices (different currencyId).
            if (isset($appCurrencyMap[$appId][$currencyId])) {
                $existingInvoiceId = $appCurrencyMap[$appId][$currencyId];

                if ($existingInvoiceId == $invoiceId) {
                    // Same invoice – fall through to update it below
                } else {
                    $this->warn("  Duplicate invoice {$invoiceId} for app {$appId} / currency {$currencyId} – already have {$existingInvoiceId}, skipping");
                    $skippedDupe++;

                    continue;
                }
            }

            // Register in the in-memory map so later iterations in this run are also guarded
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
                $this->error("Failed invoice {$invoiceId}: " . $e->getMessage());
            }
        }

        // Clean up any orphaned duplicate invoices in the DB that shouldn't be there.
        // Keep only the first invoice per app+currency (lowest Id wins as tiebreaker).
        $this->cleanOrphanedDuplicateInvoices($year, $allowedStatuses);

        $this->info("Done. Inserted: {$inserted}, Updated: {$updated}, Skipped (no app): {$skippedNoApp}, Skipped (duplicate): {$skippedDupe}");

        return Command::SUCCESS;
    }

    /**
     * Remove any extra invoices in the DB where a single app+currency has more than one row.
     * Keeps the row with the lowest Id (first created). Payments linked to removed invoices
     * are also deleted to maintain referential integrity.
     */
    protected function cleanOrphanedDuplicateInvoices(int $year, array $allowedStatuses): void
    {
        // Find app IDs for this year
        $appIds = DB::connection('mysql')
            ->table('customerapplication')
            ->where('RenewalPeriod', $year)
            ->whereIn('ApprovalStatus', $allowedStatuses)
            ->pluck('Id');

        if ($appIds->isEmpty()) {
            return;
        }

        // Find duplicates: same CustomerApplicationId + CurrencyId, keep min(Id)
        $duplicates = DB::connection('mysql')
            ->table('applicationinvoices')
            ->selectRaw('CustomerApplicationId, CurrencyId, MIN(Id) as keep_id, COUNT(*) as cnt')
            ->whereIn('CustomerApplicationId', $appIds)
            ->groupBy('CustomerApplicationId', 'CurrencyId')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $dup) {
            $toDelete = DB::connection('mysql')
                ->table('applicationinvoices')
                ->where('CustomerApplicationId', $dup->CustomerApplicationId)
                ->where('CurrencyId', $dup->CurrencyId)
                ->where('Id', '!=', $dup->keep_id)
                ->pluck('Id');

            if ($toDelete->isEmpty()) {
                continue;
            }

            // Remove payments linked to these duplicate invoices first
            $paymentsDeleted = DB::connection('mysql')
                ->table('applicationpayments')
                ->whereIn('ApplicationInvoiceId', $toDelete)
                ->delete();

            $invoicesDeleted = DB::connection('mysql')
                ->table('applicationinvoices')
                ->whereIn('Id', $toDelete)
                ->delete();

            $this->warn("  Cleaned {$invoicesDeleted} duplicate invoice(s) (and {$paymentsDeleted} payment(s)) for app={$dup->CustomerApplicationId} currency={$dup->CurrencyId}");
        }
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
