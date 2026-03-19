<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Console\Commands\SyncCustomerApplicationFromAudit;
use App\Console\Commands\SyncApplicationInvoiceFromAudit;
use App\Console\Commands\SyncApplicationPaymentFromAudit;

class SyncAllFromAudit extends Command
{
    protected $signature = 'sync:all {--year=2026 : The year to filter by}';
    protected $description = 'Sync all customerapplication, invoices and payments from auditentries';

    public function handle(): int
    {
        $year = (int) $this->option('year');

        $this->info("========================================");
        $this->info("Starting full sync for year {$year}");
        $this->info("========================================");

        // Step 1: Sync CustomerApplication
        $this->info("\n[1/3] Syncing CustomerApplication...");
        $this->call(SyncCustomerApplicationFromAudit::class, ['--year' => $year]);

        // Step 2: Sync ApplicationInvoice (requires Application to exist)
        $this->info("\n[2/3] Syncing ApplicationInvoice...");
        $this->call(SyncApplicationInvoiceFromAudit::class, ['--year' => $year]);

        // Step 3: Sync ApplicationPayment (requires Invoice to exist)
        $this->info("\n[3/3] Syncing ApplicationPayment...");
        $this->call(SyncApplicationPaymentFromAudit::class, ['--year' => $year]);

        $this->info("\n========================================");
        $this->info("Full sync completed for year {$year}");
        $this->info("========================================");

        return Command::SUCCESS;
    }
}
