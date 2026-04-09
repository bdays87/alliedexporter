<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SyncAllFromAudit extends Command
{
    protected $signature = 'sync:all
                            {--current-year=2026 : The current renewal period (AWAITING+APPROVED). All prior years get APPROVED only.}
                            {--from-year=2020 : Earliest year to sync}
                            {--only-year= : Sync a single specific year instead of the full range}';

    protected $description = 'Sync all customer applications, invoices and payments from audit entries.
                              Years before --current-year: APPROVED only.
                              --current-year: AWAITING + APPROVED.';

    public function handle(): int
    {
        $currentYear = (int) $this->option('current-year');
        $fromYear = (int) $this->option('from-year');
        $onlyYear = $this->option('only-year');

        $years = $onlyYear !== null
            ? [(int) $onlyYear]
            : range($fromYear, $currentYear);

        $this->info('========================================');
        $this->info('Starting full sync');
        $this->info('Years: ' . implode(', ', $years));
        $this->info("Current renewal period (AWAITING+APPROVED): {$currentYear}");
        $this->info('Past years (APPROVED only): ' . $fromYear . ' – ' . ($currentYear - 1));
        $this->info('========================================');

        foreach ($years as $year) {
            $isPast = $year < $currentYear;
            $label = $isPast ? 'APPROVED only' : 'AWAITING + APPROVED';

            $this->info("\n======== Year {$year} [{$label}] ========");

            $this->info("\n[1/3] Syncing CustomerApplication for {$year}...");
            $this->call(SyncCustomerApplicationFromAudit::class, [
                '--year' => $year,
                '--current-year' => $currentYear,
            ]);

            $this->info("\n[2/3] Syncing ApplicationInvoice for {$year}...");
            $this->call(SyncApplicationInvoiceFromAudit::class, [
                '--year' => $year,
                '--current-year' => $currentYear,
            ]);

            $this->info("\n[3/3] Syncing ApplicationPayment for {$year}...");
            $this->call(SyncApplicationPaymentFromAudit::class, [
                '--year' => $year,
                '--current-year' => $currentYear,
            ]);
        }

        $this->info("\n========================================");
        $this->info('Full sync completed');
        $this->info('========================================');

        return Command::SUCCESS;
    }
}
