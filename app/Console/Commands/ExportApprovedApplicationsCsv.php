<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExportApprovedApplicationsCsv extends Command
{
    protected $signature = 'export:approved-applications-csv
                            {--year=2026 : The RenewalPeriod year to export}';

    protected $description = 'Export APPROVED applications for a given RenewalPeriod from auditentries to CSV. One row per invoice (split ZWG/USD = two rows). One app per customer — latest DateUpdated wins on duplicates.';

    public function handle(): int
    {
        $year = (int) $this->option('year');

        $this->info("Reading auditentries for APPROVED applications with RenewalPeriod={$year}...");

        // Span the renewal year and the year before (renewals often start Oct prior year)
        $audits = DB::connection('mysql')
            ->table('auditentries')
            ->where('EntityName', 'CustomerApplication')
            ->whereRaw('YEAR(TIMESTAMP) IN (?, ?)', [$year - 1, $year])
            ->orderBy('TIMESTAMP', 'asc')
            ->get();

        $this->info("Found {$audits->count()} audit records");

        // Build final state per application ID — last write wins (chronological)
        $applications = [];

        foreach ($audits as $audit) {
            $data = json_decode($audit->Changes, true);

            if (! $data || ! isset($data['Id'])) {
                continue;
            }

            if (($data['RenewalPeriod'] ?? null) != $year) {
                continue;
            }

            if (strtoupper($audit->Action ?? '') === 'DELETE') {
                unset($applications[$data['Id']]);
                continue;
            }

            $applications[$data['Id']] = $data;
        }

        // Keep only APPROVED
        $approved = array_filter(
            $applications,
            fn ($data) => strtoupper($data['ApprovalStatus'] ?? '') === 'APPROVED'
        );

        // Deduplicate: one application per customer — keep latest DateUpdated
        $customerMap = [];

        foreach ($approved as $appId => $data) {
            $customerId = $data['CustomerId'] ?? null;

            if (! $customerId) {
                continue;
            }

            if (! isset($customerMap[$customerId])) {
                $customerMap[$customerId] = $appId;
            } else {
                $existingId      = $customerMap[$customerId];
                $existingUpdated = $approved[$existingId]['DateUpdated'] ?? '';
                $currentUpdated  = $data['DateUpdated'] ?? '';

                if ($currentUpdated > $existingUpdated) {
                    $this->warn("Duplicate: customer {$customerId} — replacing app {$existingId} with {$appId} (newer)");
                    $customerMap[$customerId] = $appId;
                } else {
                    $this->warn("Duplicate: customer {$customerId} — keeping app {$existingId}, skipping {$appId}");
                }
            }
        }

        // Rebuild using only the winning app per customer
        $approved = array_intersect_key($approved, array_flip(array_values($customerMap)));

        $this->info('APPROVED applications after dedup: ' . count($approved));

        if (empty($approved)) {
            $this->warn('No APPROVED applications found. CSV not created.');

            return Command::SUCCESS;
        }

        // Bulk-load customer details
        $customerIds = array_unique(array_column(array_values($approved), 'CustomerId'));
        $customers   = DB::connection('mysql')
            ->table('customers')
            ->whereIn('Id', $customerIds)
            ->get()
            ->keyBy('Id');

        // Bulk-load invoices grouped by application
        $appIds   = array_keys($approved);
        $invoices = DB::connection('mysql')
            ->table('applicationinvoices')
            ->whereIn('CustomerApplicationId', $appIds)
            ->get()
            ->groupBy('CustomerApplicationId');

        // Bulk-load payments grouped by invoice
        $invoiceIds = DB::connection('mysql')
            ->table('applicationinvoices')
            ->whereIn('CustomerApplicationId', $appIds)
            ->pluck('Id');

        $payments = DB::connection('mysql')
            ->table('applicationpayments')
            ->whereIn('ApplicationInvoiceId', $invoiceIds)
            ->get()
            ->groupBy('ApplicationInvoiceId');

        // Write CSV
        $filename = public_path('approved_applications_' . $year . '_' . date('Ymd_His') . '.csv');
        $handle   = fopen($filename, 'w');

        fputcsv($handle, [
            'ApplicationId',
            'CustomerId',
            'FirstName',
            'LastName',
            'Email',
            'Phone',
            'RegistrationNumber',
            'RenewalPeriod',
            'ApprovalStatus',
            'RegistrarStatus',
            'AccountStatus',
            'ApplicationTypeId',
            'RenewalCategoryId',
            'CertificateNumber',
            'DateCreated',
            'DateUpdated',
            'ApprovalDate',
            'InvoiceId',
            'InvoiceCurrencyId',
            'InvoiceAmountDue',
            'InvoiceTotalDue',
            'InvoiceDateCreated',
            'TotalPayments',
            'PaymentIds',
        ]);

        $rowCount = 0;

        foreach ($approved as $appId => $data) {
            $customer    = $customers->get($data['CustomerId'] ?? null);
            $appInvoices = $invoices->get($appId, collect());

            if ($appInvoices->isEmpty()) {
                // No invoice — still export the application row
                fputcsv($handle, $this->buildRow($appId, $data, $customer, null, collect()));
                $rowCount++;
            } else {
                // One row per invoice (ZWG + USD split = two rows)
                foreach ($appInvoices as $invoice) {
                    $invoicePayments = $payments->get($invoice->Id, collect());
                    fputcsv($handle, $this->buildRow($appId, $data, $customer, $invoice, $invoicePayments));
                    $rowCount++;
                }
            }
        }

        fclose($handle);

        $this->info("✅ CSV exported: {$filename}");
        $this->info("   Rows written: {$rowCount}");

        return Command::SUCCESS;
    }

    private function buildRow(int $appId, array $data, mixed $customer, mixed $invoice, mixed $invoicePayments): array
    {
        $totalPayments = $invoicePayments->sum(fn ($p) => floatval($p->Amount ?? 0));
        $paymentIds    = $invoicePayments->pluck('Id')->implode('|');

        return [
            $appId,
            $data['CustomerId'] ?? '',
            $customer->FirstName ?? '',
            $customer->LastName ?? '',
            $customer->Email ?? '',
            $customer->Phone ?? '',
            ($customer->Prefix ?? '') . ($customer->RegistrationNumber ?? ''),
            $data['RenewalPeriod'] ?? '',
            $data['ApprovalStatus'] ?? '',
            $data['RegistrarStatus'] ?? '',
            $data['AccountStatus'] ?? '',
            $data['ApplicationTypeId'] ?? '',
            $data['RenewalCategoryId'] ?? '',
            $data['CertificateNumber'] ?? '',
            $this->formatDate($data['DateCreated'] ?? null),
            $this->formatDate($data['DateUpdated'] ?? null),
            $this->formatDate($data['ApprovalDate'] ?? null),
            $invoice->Id ?? '',
            $invoice->CurrencyId ?? '',
            $invoice->AmountDue ?? '',
            $invoice->TotalDue ?? '',
            $this->formatDate($invoice->DateCreated ?? null),
            $totalPayments ?: '',
            $paymentIds,
        ];
    }

    private function formatDate(mixed $value): string
    {
        if (empty($value)) {
            return '';
        }

        try {
            return (new \DateTime((string) $value))->format('Y-m-d H:i:s');
        } catch (\Exception) {
            return (string) $value;
        }
    }
}
