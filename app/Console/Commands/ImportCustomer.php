<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Customer;
use App\Models\Newcustomer;
use Carbon\Carbon;
use Illuminate\Support\Str;


class ImportCustomer extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:import-customer';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $now = now();
        $chunkSize = 500;

        $query = Customer::with('gender', 'title');
        $countnew = Newcustomer::count();
        if ($countnew > 0) {
            $query->where('Id', '>', $countnew);
        }

        $total = $query->count();
        $this->info('Total Customers to Import: ' . $total);

        if ($total === 0) {
            $this->info('Nothing to import.');
            return;
        }

        // Pre-load all existing IDs into memory — eliminates one SELECT per row
        $existingIds = Newcustomer::pluck('id')->flip()->all();

        $counter = 0;

        $query->chunkById($chunkSize, function ($customers) use (&$counter, &$existingIds, $now) {
            $customerRows = [];

            foreach ($customers as $customer) {
                $id = $customer->Id;

                if (isset($existingIds[$id])) {
                    continue;
                }

                $customerRows[] = [
                    'id'                    => $id,
                    'uuid'                  => Str::uuid()->toString(),
                    'title'                 => $customer->title ? $customer->title->Title : 'Mr',
                    'name'                  => $customer->FirstName,
                    'surname'               => $customer->LastName,
                    'previous_name'         => $customer->PreviousName,
                    'regnumber'             => $customer->Prefix . $customer->RegistrationNumber,
                    'identificationtype'    => 'National ID',
                    'identificationnumber'  => $customer->IDnumber,
                    'gender'                => $customer->gender ? $customer->gender->Name : 'Male',
                    'email'                 => $customer->Email,
                    'phone'                 => $customer->Phone,
                    'place_of_birth'        => $customer->PlaceofBirth,
                    'dob'                   => $this->parseDate($customer->Dob),
                    'nationality_id'        => $customer->NationalityId,
                    'province_id'           => $customer->ProvinceId,
                    'city_id'               => $customer->CityId,
                    'employmentlocation_id' => $customer->EmploymentLocationId,
                    'employmentstatus_id'   => $customer->EmploymentStatusId,
                    'created_at'            => $now,
                    'updated_at'            => $now,
                ];

                $existingIds[$id] = true;
            }

            if (!empty($customerRows)) {
                try {
                    Newcustomer::insertOrIgnore($customerRows);
                    $counter += count($customerRows);
                    $this->info('Inserted ' . count($customerRows) . ' customers. Total so far: ' . $counter);
                } catch (\Exception $e) {
                    $this->error('Bulk insert failed, falling back row-by-row: ' . $e->getMessage());
                    foreach ($customerRows as $row) {
                        try {
                            Newcustomer::insertOrIgnore([$row]);
                            $counter++;
                        } catch (\Exception $ex) {
                            $this->error('Failed Customer ID ' . $row['id'] . ': ' . $ex->getMessage());
                        }
                    }
                }
            }
        }, 'Id');

        $this->info('Done. Imported ' . $counter . ' customers. Run app:import-customer-useraccount to import user accounts.');
    }

    protected function parseDate(?string $dateString): ?string
    {
        if (empty($dateString)) {
            return null;
        }

        $dateString = trim($dateString);

        // Try to parse DD/MM/YYYY format first
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $dateString, $matches)) {
            $day = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
            $month = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
            $year = $matches[3];
            $dateString = "{$year}-{$month}-{$day}";
        }

        // Try to parse using Carbon
        try {
            $date = Carbon::parse($dateString);

            return $date->format('Y-m-d');
        } catch (\Exception $e) {
            return null;
        }
    }
}
