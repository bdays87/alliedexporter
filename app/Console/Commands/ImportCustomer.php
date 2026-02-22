<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Customer;
use App\Models\Newcustomer;
use App\Models\Newuser;
use App\Models\Newcustomeruser;
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
        $countnew = Newcustomer::count();
        $oldcustoer = [];
        if ($countnew > 0) {
            $oldcustoer = Customer::with('gender', 'title', 'User', 'province', 'city')->where('id', '>', $countnew)->get();
        } else {
            $oldcustoer  =  Customer::with('gender', 'title', 'User', 'province', 'city')->get();
        }
        $this->info('Total Customer: ' . $oldcustoer->count());
        $counter = 0;
        $userCounter = 0;
        foreach ($oldcustoer as $customer) {
            $id = $customer->Id;
            $uuid = Str::uuid()->toString();
            $title = $customer->title ? $customer->title->Title : "Mr";
            $name = $customer->FirstName;
            $surname = $customer->LastName;
            $previous_name = $customer->PreviousName;
            $regnumber = $customer->Prefix . $customer->RegistrationNumber;
            $identificationtype = "National ID";
            $identificationnumber = $customer->IDnumber;
            $gender = $customer->gender ? $customer->gender->Name : "Male";
            $email = $customer->Email;
            $phone = $customer->Phone;
            $place_of_birth = $customer->PlaceofBirth;
            $dob = $customer->Dob;
            $nationality_id = $customer->NationalityId;
            $province_id = $customer->ProvinceId;
            $city_id = $customer->CityId;
            $employmentlocation_id = $customer->EmploymentLocationId;
            $employmentstatus_id = $customer->EmploymentStatusId;
            $newcustomer = new Newcustomer();
            $newcustomer->id = $id;
            $newcustomer->uuid = $uuid;
            $newcustomer->title = $title;
            $newcustomer->name = $name;
            $newcustomer->surname = $surname;
            $newcustomer->previous_name = $previous_name;
            $newcustomer->regnumber = $regnumber;
            $newcustomer->identificationtype = $identificationtype;
            $newcustomer->identificationnumber = $identificationnumber;
            $newcustomer->gender = $gender;
            $newcustomer->email = $email;
            $newcustomer->phone = $phone;
            $newcustomer->place_of_birth = $place_of_birth;
            $newcustomer->dob = $this->parseDate($dob);
            $newcustomer->nationality_id = $nationality_id;
            $newcustomer->province_id = $province_id;
            $newcustomer->city_id = $city_id;
            $newcustomer->employmentlocation_id = $employmentlocation_id;
            $newcustomer->employmentstatus_id = $employmentstatus_id;
            $newcustomer->save();
            if ($customer->User) {
                $checkuser = Newuser::where('email', $customer->User->Email)->first();
                if ($checkuser) {
                    continue;
                }
                $user = new Newuser();
                $user->uuid = Str::uuid()->toString();
                $user->accounttype_id = 2;
                $user->name = $customer->FirstName;
                $user->surname = $customer->LastName;
                $user->email = filter_var($customer->User->Email, FILTER_VALIDATE_EMAIL) ? $customer->User->Email : $customer->Email ?? $regnumber . $name . '@example.com';
                $user->phone = $customer->Phone;
                $user->password = $customer->RawPassword ? bcrypt($customer->RawPassword) : bcrypt('defaultpassword');
                $user->save();
                $newcustomeruser = new Newcustomeruser();
                $newcustomeruser->customer_id = $newcustomer->id;
                $newcustomeruser->user_id = $user->id;
                $newcustomeruser->save();
                $userCounter++;
                $this->info('User ' . $userCounter . ' imported successfully.');
            }
            $counter++;
            $this->info('Customer ' . $counter . ' imported successfully.');
        }
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
