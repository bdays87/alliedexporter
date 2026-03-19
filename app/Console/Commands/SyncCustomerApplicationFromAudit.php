<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncCustomerApplicationFromAudit extends Command
{
    protected $signature = 'sync:customerapplication {--year=2026 : The year to filter by}';
    protected $description = 'Sync customerapplication from auditentries - finds final status from full audit trail';

    public function handle(): int
    {
        $year = (int) $this->option('year');

        $this->info("Syncing CustomerApplication for year {$year}...");

        // Get all audit entries for CustomerApplication in 2025 and 2026
        $audits = DB::connection('mysql')
            ->table('auditentries')
            ->where('EntityName', 'CustomerApplication')
            ->whereRaw('YEAR(TIMESTAMP) IN (?, ?)', [$year - 1, $year])
            ->orderBy('TIMESTAMP', 'asc')
            ->get();

        $this->info("Found {$audits->count()} audit records");

        // Group by application ID and track all status changes
        $applicationStatuses = [];

        foreach ($audits as $audit) {
            $data = json_decode($audit->Changes, true);

            if (!$data || !isset($data['Id'])) {
                continue;
            }

            $appId = $data['Id'];
            $actionType = $audit->Action ?? 'UNKNOWN';

            // Only process if RenewalPeriod matches the year
            if (($data['RenewalPeriod'] ?? null) != $year) {
                continue;
            }

            // Skip DELETE actions - check if application was deleted
            if (strtoupper($actionType) === 'DELETE') {
                $this->info("Application {$appId}: Marked as DELETED, skipping");
                continue;
            }

            // Get timestamp
            $timestamp = $audit->timestamp ?? $audit->Timestamp ?? $audit->DateCreated ?? now();

            // Track each status change for this application (INSERT, UPDATE)
            $applicationStatuses[$appId][] = [
                'data' => $data,
                'timestamp' => $timestamp,
                'action' => $actionType,
            ];
        }

        $this->info("Found " . count($applicationStatuses) . " unique applications (excluding deleted)");

        // Track customer+year combinations to detect duplicates
        $customerYearMap = [];
        $inserted = 0;
        $updated = 0;
        $skippedNoCustomerId = 0;
        $skippedDuplicates = 0;
        $skippedDeleted = 0;

        foreach ($applicationStatuses as $appId => $statusChanges) {
            // Get the final status (last entry in the array)
            $finalStatus = end($statusChanges);
            $data = $finalStatus['data'];
            $finalAction = $finalStatus['action'];

            $approvalStatus = $data['ApprovalStatus'] ?? 'UNKNOWN';
            $this->info("Application {$appId}: Final status = {$approvalStatus} (Action: {$finalAction})");

            // Skip if CustomerId is null
            if (empty($data['CustomerId'])) {
                $this->warn("  → Skipping ID {$appId}: CustomerId is null");
                $logData = [
                    'Id' => $appId,
                    'reason' => 'CustomerId is null',
                    'data' => $data,
                    'timestamp' => now()->toDateTimeString(),
                ];
                $this->logSkippedRecord('customerapplication', $logData);
                $skippedNoCustomerId++;
                continue;
            }

            // Check for duplicate: one application per customer per year
            $customerId = $data['CustomerId'];
            $customerYearKey = $customerId . '_' . $year;

            if (isset($customerYearMap[$customerYearKey])) {
                // Already have an application for this customer in this year
                // Check which one has more advanced status
                $existingAppId = $customerYearMap[$customerYearKey];
                $existingStatus = $applicationStatuses[$existingAppId] ?? [];
                $existingFinal = end($existingStatus);
                $existingData = $existingFinal['data'] ?? [];
                $existingApproval = $existingData['ApprovalStatus'] ?? 'UNKNOWN';

                // Priority: APPROVED > AWAITING > PENDING
                $currentPriority = $this->getApprovalPriority($approvalStatus);
                $existingPriority = $this->getApprovalPriority($existingApproval);

                if ($currentPriority > $existingPriority) {
                    // Current one has higher priority, use it instead
                    $this->info("  → Replacing {$existingAppId} ({$existingApproval}) with {$appId} ({$approvalStatus}) - more advanced status");
                    $customerYearMap[$customerYearKey] = $appId;
                } else {
                    $this->warn("  → Skipping duplicate: Customer {$customerId} already has application {$existingAppId} ({$existingApproval})");
                    $skippedDuplicates++;
                    continue;
                }
            } else {
                $customerYearMap[$customerYearKey] = $appId;
            }

            // Check if already exists in database
            $exists = DB::connection('mysql')
                ->table('customerapplication')
                ->where('Id', $appId)
                ->exists();

            try {
                // Parse datetime values to MySQL format
                $dateCreated = $this->parseDateTime($data['DateCreated'] ?? null);
                $dateUpdated = $this->parseDateTime($data['DateUpdated'] ?? null);
                $approvalDate = $this->parseDateTime($data['ApprovalDate'] ?? null);

                // Log the full data object
                $this->info("Data: " . json_encode([
                    'Id' => $data['Id'] ?? null,
                    'CustomerId' => $data['CustomerId'] ?? null,
                    'CustomerProfessionId' => $data['CustomerProfessionId'] ?? null,
                    'PaymentItemId' => $data['PaymentItemId'] ?? null,
                    'RenewalPeriod' => $data['RenewalPeriod'] ?? null,
                    'ApprovalStatus' => $approvalStatus,
                    'DateCreated' => $dateCreated,
                    'DateUpdated' => $dateUpdated,
                    'ApprovalDate' => $approvalDate,
                ]));

                // Validate and set null for missing foreign keys
                $customerProfessionId = $data['CustomerProfessionId'];
                if ($customerProfessionId) {
                    $customerProfExists = DB::connection('mysql')->table('customerprofessions')
                        ->where('Id', $customerProfessionId)
                        ->exists();
                    if (!$customerProfExists) {
                        $this->warn("  Warning: CustomerProfessionId {$customerProfessionId} not found, setting to null");
                        $customerProfessionId = null;
                    }
                }

                // If CustomerProfessionId is still null, try to find it from CustomerProfessions table using CustomerId
                if (!$customerProfessionId && ($data['CustomerId'] ?? null)) {
                    $customerProf = DB::connection('mysql')->table('customerprofessions')
                        ->where('CustomerId', $data['CustomerId'])
                        ->first();
                    if ($customerProf) {
                        $customerProfessionId = $customerProf->Id;
                        $this->info("  Found CustomerProfessionId from CustomerProfessions table: {$customerProfessionId}");
                    }
                }

                $customerIdVal = $data['CustomerId'];
                if ($customerIdVal) {
                    $customerExists = DB::connection('mysql')->table('customers')
                        ->where('Id', $customerIdVal)
                        ->exists();
                    if (!$customerExists) {
                        $this->warn("  Warning: CustomerId {$customerIdVal} not found, setting to null");
                        $customerIdVal = null;
                    }
                }

                $paymentItemId = $data['PaymentItemId'];
                if ($paymentItemId) {
                    $paymentItemExists = DB::connection('mysql')->table('paymentitems')
                        ->where('Id', $paymentItemId)
                        ->exists();
                    if (!$paymentItemExists) {
                        $this->warn("  Warning: PaymentItemId {$paymentItemId} not found, setting to null");
                        $paymentItemId = null;
                    }
                }

                if ($exists) {
                    // Update with the final status from audit trail
                    DB::connection('mysql')->table('customerapplication')
                        ->where('Id', $appId)
                        ->update([
                            'CustomerId' => $customerIdVal,
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
                        ]);

                    $this->info("  → Updated with final status: {$approvalStatus}");
                    $updated++;
                } else {
                    DB::connection('mysql')->table('customerapplication')->insert([
                        'Id' => $data['Id'],
                        'CustomerId' => $customerIdVal,
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
                        'DateCreated' => $dateCreated,
                        'DateUpdated' => $dateUpdated,
                        'ApprovalDate' => $approvalDate,
                    ]);

                    $this->info("  → Inserted with final status: {$approvalStatus}");
                    $inserted++;
                }

            } catch (\Exception $e) {
                $this->error("Failed ID {$appId} - " . $e->getMessage());
                $this->error("  Full data: " . json_encode($data, JSON_PRETTY_PRINT));
            }
        }

        $this->info("Done syncing CustomerApplication. Inserted: {$inserted}, Updated: {$updated}, Skipped (no CustomerId): {$skippedNoCustomerId}, Skipped (duplicates): {$skippedDuplicates}");

        return Command::SUCCESS;
    }

    /**
     * Get approval priority for comparison
     */
    protected function getApprovalPriority(?string $status): int
    {
        $status = strtoupper($status ?? '');
        switch ($status) {
            case 'APPROVED':
                return 3;
            case 'AWAITING':
                return 2;
            case 'PENDING':
                return 1;
            default:
                return 0;
        }
    }

    /**
     * Log skipped records to a file in public folder
     */
    protected function logSkippedRecord(string $type, array $data): void
    {
        $filename = public_path('sync_skipped_' . $type . '_' . date('Y-m-d') . '.log');
        $logEntry = json_encode($data) . "\n";
        file_put_contents($filename, $logEntry, FILE_APPEND);
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
