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

        // Past years: APPROVED only. Current year: AWAITING + APPROVED.
        $allowedStatuses = ($year < $currentYear)
            ? ['APPROVED']
            : ['AWAITING', 'APPROVED'];

        $this->info("Syncing CustomerApplication for year {$year} (allowed: " . implode(', ', $allowedStatuses) . ')...');

        $audits = DB::connection('mysql')
            ->table('auditentries')
            ->where('EntityName', 'CustomerApplication')
            ->whereRaw('YEAR(TIMESTAMP) IN (?, ?)', [$year - 1, $year])
            ->orderBy('TIMESTAMP', 'asc')
            ->get();

        $this->info("Found {$audits->count()} audit records");

        // Group all changes per application ID, replaying history to get final state
        $applicationChanges = [];

        foreach ($audits as $audit) {
            $data = json_decode($audit->Changes, true);

            if (! $data || ! isset($data['Id'])) {
                continue;
            }

            // Only care about this renewal period
            if (($data['RenewalPeriod'] ?? null) != $year) {
                continue;
            }

            $appId = $data['Id'];

            // Hard-deleted records are dropped entirely
            if (strtoupper($audit->Action ?? '') === 'DELETE') {
                $this->info("Application {$appId}: DELETED – skipping");
                unset($applicationChanges[$appId]);

                continue;
            }

            $applicationChanges[$appId][] = [
                'data' => $data,
                'timestamp' => $audit->Timestamp ?? now(),
                'action' => $audit->Action ?? 'UNKNOWN',
            ];
        }

        $this->info('Found ' . count($applicationChanges) . ' unique applications (excluding deleted)');

        // Resolve the single winning application per customer per renewal period.
        // Priority: APPROVED > AWAITING > PENDING > other.
        // We also check the DB so re-runs don't create duplicates.
        $customerYearMap = []; // customerId_year => appId

        foreach ($applicationChanges as $appId => $changes) {
            $finalData = end($changes)['data'];
            $approvalStatus = strtoupper($finalData['ApprovalStatus'] ?? 'UNKNOWN');

            if (! in_array($approvalStatus, $allowedStatuses)) {
                continue;
            }

            $customerId = $finalData['CustomerId'] ?? null;

            if (! $customerId) {
                continue;
            }

            $key = $customerId . '_' . $year;

            if (! isset($customerYearMap[$key])) {
                $customerYearMap[$key] = $appId;
            } else {
                $existingId = $customerYearMap[$key];
                $existingStatus = strtoupper(end($applicationChanges[$existingId])['data']['ApprovalStatus'] ?? '');

                if ($this->approvalPriority($approvalStatus) > $this->approvalPriority($existingStatus)) {
                    $this->info("  Replacing app {$existingId} ({$existingStatus}) with {$appId} ({$approvalStatus}) for customer {$customerId}");
                    $customerYearMap[$key] = $appId;
                } else {
                    $this->warn("  Duplicate skipped: customer {$customerId} already has app {$existingId} ({$existingStatus}), ignoring {$appId} ({$approvalStatus})");
                }
            }
        }

        // Also enforce uniqueness against what is already in the DB.
        // If a customer already has an app for this year in the DB that we are NOT about to upsert,
        // remove those stale duplicates.
        $winningAppIds = array_values($customerYearMap);

        if (! empty($winningAppIds)) {
            $deleted = DB::connection('mysql')
                ->table('customerapplication')
                ->where('RenewalPeriod', $year)
                ->whereNotIn('Id', $winningAppIds)
                ->delete();

            if ($deleted > 0) {
                $this->warn("  Removed {$deleted} stale duplicate application(s) from DB for year {$year}");
            }
        }

        $inserted = 0;
        $updated = 0;
        $skippedNoCustomer = 0;

        foreach ($customerYearMap as $key => $appId) {
            $changes = $applicationChanges[$appId];
            $data = end($changes)['data'];
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
                $this->error("Failed ID {$appId}: " . $e->getMessage());
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
        $file = public_path('sync_skipped_' . $type . '_' . date('Y-m-d') . '.log');
        file_put_contents($file, json_encode($data) . "\n", FILE_APPEND);
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
