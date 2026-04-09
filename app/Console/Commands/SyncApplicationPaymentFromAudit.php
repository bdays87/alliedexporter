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

        $this->info("Syncing ApplicationPayment for year {$year} (apps: " . implode(', ', $allowedStatuses) . ')...');

        $audits = DB::connection('mysql')
            ->table('auditentries')
            ->where('EntityName', 'ApplicationPayment')
            ->whereRaw('YEAR(TIMESTAMP) IN (?, ?)', [$year - 1, $year])
            ->orderBy('TIMESTAMP', 'asc')
            ->get();

        $this->info("Found {$audits->count()} audit records");

        // Replay audit history to get the final state of each payment
        $paymentChanges = [];

        foreach ($audits as $audit) {
            $data = json_decode($audit->Changes, true);

            if (! $data || ! isset($data['Id'])) {
                continue;
            }

            $paymentChanges[$data['Id']][] = [
                'data' => $data,
                'timestamp' => $audit->Timestamp ?? now(),
            ];
        }

        $this->info('Found ' . count($paymentChanges) . ' unique payments in audit');

        // Load valid invoice IDs for this year in one query to avoid N+1
        // An invoice is valid if its application is in the correct year + allowed status
        $validInvoiceIds = DB::connection('mysql')
            ->table('applicationinvoices as i')
            ->join('customerapplication as a', 'a.Id', '=', 'i.CustomerApplicationId')
            ->where('a.RenewalPeriod', $year)
            ->whereIn('a.ApprovalStatus', $allowedStatuses)
            ->pluck('i.Id')
            ->flip()
            ->all();

        $inserted = 0;
        $updated = 0;
        $skippedNoInvoice = 0;

        foreach ($paymentChanges as $paymentId => $changes) {
            $finalData = end($changes)['data'];

            $invoiceId = $finalData['ApplicationInvoiceId'] ?? null;

            if (! $invoiceId || ! isset($validInvoiceIds[$invoiceId])) {
                $skippedNoInvoice++;

                continue;
            }

            $this->info("Payment {$paymentId}: invoice={$invoiceId}");

            try {
                $dateCreated = $this->parseDateTime($finalData['DateCreated'] ?? null);
                $dateUpdated = $this->parseDateTime($finalData['DateUpdated'] ?? null);
                $dateDeleted = $this->parseDateTime($finalData['DateDeleted'] ?? null);

                $exists = DB::connection('mysql')
                    ->table('applicationpayments')
                    ->where('Id', $paymentId)
                    ->exists();

                $record = [
                    'ApplicationInvoiceId' => $invoiceId,
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
                $this->error("Failed payment {$paymentId}: " . $e->getMessage());
            }
        }

        // Remove any payments in the DB that are now orphaned
        // (their invoice was cleaned up or never belonged to this year)
        $this->cleanOrphanedPayments($year, $allowedStatuses);

        $this->info("Done. Inserted: {$inserted}, Updated: {$updated}, Skipped (no invoice/app): {$skippedNoInvoice}");

        return Command::SUCCESS;
    }

    /**
     * Delete payments whose invoice no longer belongs to a valid application for this year.
     */
    protected function cleanOrphanedPayments(int $year, array $allowedStatuses): void
    {
        $validInvoiceIds = DB::connection('mysql')
            ->table('applicationinvoices as i')
            ->join('customerapplication as a', 'a.Id', '=', 'i.CustomerApplicationId')
            ->where('a.RenewalPeriod', $year)
            ->whereIn('a.ApprovalStatus', $allowedStatuses)
            ->pluck('i.Id');

        if ($validInvoiceIds->isEmpty()) {
            return;
        }

        // Find payments for this year's invoices that point to an invoice not in the valid set
        $orphaned = DB::connection('mysql')
            ->table('applicationpayments')
            ->whereIn('ApplicationInvoiceId', function ($q) use ($year, $allowedStatuses) {
                $q->select('i.Id')
                    ->from('applicationinvoices as i')
                    ->join('customerapplication as a', 'a.Id', '=', 'i.CustomerApplicationId')
                    ->where('a.RenewalPeriod', $year);
            })
            ->whereNotIn('ApplicationInvoiceId', $validInvoiceIds)
            ->delete();

        if ($orphaned > 0) {
            $this->warn("  Removed {$orphaned} orphaned payment(s) for year {$year}");
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
