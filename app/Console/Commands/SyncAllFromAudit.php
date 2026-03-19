<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SyncAllFromAudit extends Command
{
    protected $signature = 'sync:all
                            {--year=2026 : Sync a single specific year}
                            {--all-years : Sync all years from --from-year up to --year}
                            {--from-year=2020 : Start year when using --all-years}';

    protected $description = 'Sync customerapplication, invoices and payments from auditentries. Past years: APPROVED only. Current year: AWAITING + APPROVED.';

    public function handle(): int
    {
        $currentYear = (int) $this->option('year');
        $allYears = (bool) $this->option('all-years');
        $fromYear = (int) $this->option('from-year');

        $years = $allYears
            ? range($fromYear, $currentYear)
            : [$currentYear];

        $this->info('========================================');
        $this->info('Starting full sync');
        $this->info('Years: '.implode(', ', $years));
        $this->info("Current year (AWAITING+APPROVED): {$currentYear}");
        $this->info('Past years (APPROVED only): all others');
        $this->info('========================================');

        foreach ($years as $year) {
            $this->info("\n======== Year {$year} ========");

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
