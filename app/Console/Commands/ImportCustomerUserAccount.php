<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Newcustomer;
use App\Models\Newuser;
use App\Models\Newcustomeruser;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ImportCustomerUserAccount extends Command
{
    protected $signature = 'app:import-customer-useraccount';

    protected $description = 'Import user accounts and link them to imported customers';

    public function handle()
    {
        $chunkSize = 200;

        // Only process customers that have a User in the old system
        $total = Customer::has('User')->count();
        $this->info('Total Customers with User accounts: ' . $total);

        if ($total === 0) {
            $this->info('Nothing to import.');
            return;
        }

        // Pre-load existing customer IDs — only import users for already-imported customers
        $existingCustomerIds = Newcustomer::pluck('id')->flip()->all();

        // Pre-load existing user emails to avoid duplicate lookups
        $existingUserEmails = Newuser::pluck('id', 'email')->all();

        // Pre-load existing customer-user links keyed by customer_id|user_id
        $existingLinks = Newcustomeruser::all()
            ->keyBy(fn($l) => $l->customer_id . '|' . $l->user_id);

        $counter = 0;

        Customer::with('User')
            ->has('User')
            ->chunkById($chunkSize, function ($customers) use (
                &$counter, &$existingUserEmails, &$existingLinks, $existingCustomerIds
            ) {
                foreach ($customers as $customer) {
                    // Skip if customer was not imported yet
                    if (!isset($existingCustomerIds[$customer->Id])) {
                        $this->warn('Customer ID ' . $customer->Id . ' not in new system yet. Run app:import-customer first.');
                        continue;
                    }

                    try {
                        $regnumber = $customer->Prefix . $customer->RegistrationNumber;

                        // Guard against hasMany relation returning a collection
                        $userModel = $customer->User instanceof \Illuminate\Database\Eloquent\Collection
                            ? $customer->User->first()
                            : $customer->User;

                        if (!$userModel) {
                            $this->warn('No user model for Customer ID: ' . $customer->Id);
                            continue;
                        }

                        $email = $userModel->Email;

                        if (empty($email)) {
                            $this->warn('Empty email for Customer ID: ' . $customer->Id . ' — skipping.');
                            continue;
                        }

                        // Resolve the final email upfront so we can check it against existing users
                        $resolvedEmail = filter_var($email, FILTER_VALIDATE_EMAIL)
                            ? $email
                            : ($customer->Email ?? $regnumber . $customer->FirstName . '@example.com');

                        // Check both the original and resolved emails against existing users
                        $existingUserId = $existingUserEmails[$email]
                            ?? $existingUserEmails[$resolvedEmail]
                            ?? null;

                        if ($existingUserId) {
                            // User already exists — just ensure the link exists
                            $linkKey = $customer->Id . '|' . $existingUserId;
                            if (!isset($existingLinks[$linkKey])) {
                                $link = new Newcustomeruser();
                                $link->customer_id = $customer->Id;
                                $link->user_id     = $existingUserId;
                                $link->save();
                                $existingLinks[$linkKey] = true;
                            }
                        } else {
                            $user                 = new Newuser();
                            $user->uuid           = Str::uuid()->toString();
                            $user->accounttype_id = 2;
                            $user->name           = $customer->FirstName;
                            $user->surname        = $customer->LastName;
                            $user->email          = $resolvedEmail;
                            $user->phone          = $customer->Phone;
                            $user->password       = $customer->RawPassword
                                ? bcrypt($customer->RawPassword)
                                : bcrypt('defaultpassword');
                            $user->save();

                            $link              = new Newcustomeruser();
                            $link->customer_id = $customer->Id;
                            $link->user_id     = $user->id;
                            $link->save();

                            $existingUserEmails[$resolvedEmail]               = $user->id;
                            $existingLinks[$customer->Id . '|' . $user->id]  = true;

                            $counter++;
                            $this->info('User account imported for Customer ID: ' . $customer->Id . ' (' . $resolvedEmail . ')');
                        }
                    } catch (\Exception $e) {
                        $this->error('Error importing user for Customer ID: ' . $customer->Id . ' — ' . $e->getMessage());
                    }
                }
            }, 'Id');

        $this->info('Done. Created ' . $counter . ' new user accounts.');
    }
}
