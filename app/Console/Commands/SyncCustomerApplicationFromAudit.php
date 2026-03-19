<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncCustomerApplicationFromAudit extends Command
{
    protected $signature = 'sync:customerapplication
                            {--year=2026 : The renewal period year to filter by}
                            {--current-year=2026 : The actual current year (used to decide status filter)}';

    protected $description = 'Sync customerapplication from auditentries. Past years: APPROVED only. Current year: AWAITING + APPROVED. One app per customer per renewal period.';

    public function handle(): int
    {
        $year = (int) $this->option('year');
        $currentYear = (int) $this->option('current-year');

        // For past years only import APPROVED; for current year import AWAITING + APPROVED
        $allowedStatuses = ($year < $currentYear)
            ? ['APPROVED']
            : ['AWAITING', 'APPROVED'];

        $this->info("Syncing CustomerApplication for year {$year} (allowed: ".implode(', ', $allowedStatuses).')...');

        // Pull audit entries spanning the renewal year and the year before
        // (renewals often start in Oct of the prior year)
        $audits = DB::connection('mysql')
            ->table('auditentries')
            ->where('EntityName', 'CustomerApplication')
            ->whereRaw('YEAR(TIMESTAMP) IN (?, ?)', [$year - 1, $year])
            ->orderBy('TIMESTAMP', 'asc')
            ->get();

        $this->info("Found {$audits->count()} audit records");

        // Group all changes per application ID
        $applicationStatuses = [];

        foreach ($audits as $audit) {
            $data = json_decode($audit->Changes, true);

            if (! $data || ! isset($data['Id'])) {
                continue;
            }

            $appId = $data['Id'];
            $actionType = $audit->Action ?? 'UNKNOWN';

            // Only care about this renewal period
            if (($data['RenewalPeriod'] ?? null) != $year) {
                continue;
            }

            // Skip hard-deleted records
            if (strtoupper($actionType) === 'DELETE') {
                $this->info("Application {$appId}: DELETED – skipping");
                unset($applicationStatuses[$appId]);

                continue;
            }

            $timestamp = $audit->Timestamp ?? $audit->timestamp ?? now();

            $applicationStatuses[$appId][] = [
                'data' => $data,
                'timestamp' => $timestamp,
                'action' => $actionType,
            ];
        }

        $this->info('Found '.count($applicationStatuses).' unique applications (excluding deleted)');

        // One application per customer per renewal period
        // Key: customerId_year → best appId
        $customerYearMap = [];

        // First pass: resolve which single app wins per customer
        foreach ($applicationStatuses as $appId => $statusChanges) {
            $finalData = end($statusChanges)['data'];
            $approvalStatus = strtoupper($finalData['ApprovalStatus'] ?? 'UNKNOWN');

            // Skip statuses not allowed for this year
            if (! in_array($approvalStatus, $allowedStatuses)) {
                continue;
            }

            $customerId = $finalData['CustomerId'] ?? null;
            if (! $customerId) {
                continue;
            }

            $key = $customerId.'_'.$year;

            if (! isset($customerYearMap[$key])) {
                $customerYearMap[$key] = $appId;
            } else {
                // Keep the one with the higher approval priority
                $existingId = $customerYearMap[$key];
                $existingFinal = end($applicationStatuses[$existingId])['data'];
                $existingStatus = strtoupper($existingFinal['ApprovalStatus'] ?? '');

                if ($this->approvalPriority($approvalStatus) > $this->approvalPriority($existingStatus)) {
                    $this->info("  Replacing app {$existingId} ({$existingStatus}) with {$appId} ({$approvalStatus}) for customer {$customerId}");
                    $customerYearMap[$key] = $appId;
                } else {
                    $this->warn("  Duplicate skipped: customer {$customerId} already has app {$existingId} ({$existingStatus}), ignoring {$appId} ({$approvalStatus})");
                }
            }
        }

        $inserted = 0;
        $updated = 0;
        $skippedNoCustomer = 0;
        $skippedStatus = 0;

        foreach ($customerYearMap as $key => $appId) {
            $statusChanges = $applicationStatuses[$appId];
            $finalEntry = end($statusChanges);
            $data = $finalEntry['data'];
            $approvalStatus = strtoupper($data['ApprovalStatus'] ?? 'UNKNOWN');

            $this->info("Application {$appId}: status={$approvalStatus}, customer={$data['CustomerId']}");

            if (empty($data['CustomerId'])) {
                $this->warn('  → Skipping: CustomerId is null');
                $this->logSkipped('customerapplication', ['Id' => $appId, 'reason' => 'CustomerId is null', 'data' => $data]);
                $skippedNoCustomer++;

                continue;
            }

            try {
                $dateCreated = $this->parseDateTime($data['DateCreated'] ?? null);
                $dateUpdated = $this->parseDateTime($data['DateUpdated'] ?? null);
                $approvalDate = $this->parseDateTime($data['ApprovalDate'] ?? null);

                // Validate foreign keys
                $customerProfessionId = $this->resolveCustomerProfessionId(
                    $data['CustomerProfessionId'] ?? null,
                    $data['CustomerId']
                );

                $customerId = $this->validateFk('customers', 'Id', $data['CustomerId']);

                $paymentItemId = $this->validateFk('paymentitems', 'Id', $data['PaymentItemId'] ?? null);

                $exists = DB::connection('mysql')
                    ->table('customerapplication')
                    ->where('Id', $appId)
                    ->exists();

                $record = [
                    'CustomerId' => $customerId,
                    'CustomerProfessionId' => $customerProfessionId,
                    'PaymentItemId' => $paymentItemId,
                    'PaymentMethodId' => $data['PaymentMethodId'] ?? null,
                    'RenewalCategoryId' => $data['RenewalCategoryId'] ?? null,
                    'RenewalPeriod' => $data['RenewalPeriod'],
                    'RenewalStatusId' => $data['RenewalStatusId'] ?? null,
                    'ApplicationTypeId' => $data['ApplicationTypeId'] ?? null,
                    'RegisterCategoryId' => $data['RegisterCategoryId'] ?? null,
                    'Cdpoints' => $data['Cdpoints'] ?? null,
                    'Placement' => $data['Placement'] ?? null,
                    'CertificateNumber' => $data['CertificateNumber'] ?? null,
                    'AccountStatus' => $data['AccountStatus'] ?? 0,
                    'ApprovalStatus' => $approvalStatus,
                    'RegistrarStatus' => $data['RegistrarStatus'] ?? 0,
                    'Comment' => $data['Comment'] ?? null,
                    'RegistrationComment' => $data['RegistrationComment'] ?? null,
                    'AccountComment' => $data['AccountComment'] ?? null,
                    'ProofPaymentComment' => $data['ProofPaymentComment'] ?? null,
                    'balance' => $data['balance'] ?? null,
                    'DateUpdated' => $dateUpdated,
                    'ApprovalDate' => $approvalDate,
                ];

                if ($exists) {
                    DB::connection('mysql')->table('customerapplication')
                        ->where('Id', $appId)
                        ->update($record);
                    $this->info('  → Updated');
                    $updated++;
                } else {
                    DB::connection('mysql')->table('customerapplication')
                        ->insert(array_merge($record, [
                            'Id' => $data['Id'],
                            'DateCreated' => $dateCreated,
                        ]));
                    $this->info('  → Inserted');
                    $inserted++;
                }
            } catch (\Exception $e) {
                $this->error("Failed ID {$appId}: ".$e->getMessage());
            }
        }

        $this->info("Done. Inserted: {$inserted}, Updated: {$updated}, Skipped (no customer): {$skippedNoCustomer}");

        return Command::SUCCESS;
    }

    protected function approvalPriority(?string $status): int
    {
        return match (strtoupper($status ?? '')) {
            'APPROVED' => 3,
            'AWAITING' => 2,
            'PENDING' => 1,
            default => 0,
        };
    }

    protected function resolveCustomerProfessionId(?int $id, ?int $customerId): ?int
    {
        if ($id) {
            $exists = DB::connection('mysql')->table('customerprofessions')->where('Id', $id)->exists();
            if ($exists) {
                return $id;
            }
            $this->warn("  CustomerProfessionId {$id} not found, attempting lookup by CustomerId");
        }

        if ($customerId) {
            $row = DB::connection('mysql')->table('customerprofessions')->where('CustomerId', $customerId)->first();
            if ($row) {
                $this->info("  Resolved CustomerProfessionId={$row->Id} from CustomerId={$customerId}");

                return $row->Id;
            }
        }

        return null;
    }

    protected function validateFk(string $table, string $column, mixed $value): mixed
    {
        if (! $value) {
            return null;
        }

        $exists = DB::connection('mysql')->table($table)->where($column, $value)->exists();

        if (! $exists) {
            $this->warn("  FK {$table}.{$column}={$value} not found, setting null");

            return null;
        }

        return $value;
    }

    protected function logSkipped(string $type, array $data): void
    {
        $file = public_path('sync_skipped_'.$type.'_'.date('Y-m-d').'.log');
        file_put_contents($file, json_encode($data)."\n", FILE_APPEND);
    }

    protected function parseDateTime(?string $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        try {
            $date = new \DateTime($value);

            return $date->format('Y-m-d H:i:s');
        } catch (\Exception) {
            return null;
        }
    }
}
