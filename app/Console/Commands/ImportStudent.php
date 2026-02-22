<?php

namespace App\Console\Commands;

use App\Models\NewStudent;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Console\Command;
use App\Models\Newuser;
use Illuminate\Support\Str;
class ImportStudent extends Command
{
    protected $signature = 'app:import-students';

    protected $description = 'Import students from ahpczportalcsharp to ahpczportalphp';

    public function handle()
    {
        $countNew = NewStudent::count();

        if ($countNew > 0) {
            $oldStudents = Student::where('Id', '>', $countNew)->get();
        } else {
            $oldStudents = Student::all();
        }
        $logFile = storage_path('logs/duplicate_students.txt');
        $this->info('Total Students Found: '.$oldStudents->count());
        $counter = 0;

        foreach ($oldStudents as $student) {

            // Avoid duplicates
            $exists = NewStudent::where('regnumber', $student->RegistrationNumber)
                ->orWhere('email', $student->Email)
                ->first();

            $log = date('Y-m-d H:i:s').
               " | DUPLICATE REG: {$student->RegistrationNumber}| NAME: {$student->Name} | SURNAME: {$student->Surname} | EMAIL: {$student->Email}\n";

            file_put_contents($logFile, $log, FILE_APPEND);

            $new = new NewStudent;
            $new->id = $student->Id;
            $new->name = $student->Name;
            $new->surname = $student->Surname;
            $new->regnumber = $student->RegistrationNumber;
            $new->dob = $this->parseDate($student->Dob);
            $new->pob = $student->Pob;
            $new->gender = $student->Gender ?? 'Male';
            $new->nationality = $student->Nationality ?? 'Zimbabwe';
            $new->address = $student->Address;
            $new->phone = $student->Cellphone;

           $hasRealEmail = filter_var($student->Email, FILTER_VALIDATE_EMAIL);

            if ($hasRealEmail) {
                $useremail = strtolower($student->Email);
            } else {
                $useremail = null; // DO NOT generate fake email
            }

            $new->email = $useremail;

            $new->email = $useremail;
            $new->idnumber = $student->Idnumber;

            // Handle C# DateTimeOffset format and convert to Laravel timestamp
            $dateCreated = $this->parseDateTimeOffset($student->DateCreated);
            // If DateCreated is DateTime.MinValue, try to use DateUpdated instead
            if (! $dateCreated) {
                $dateCreated = $this->parseDateTimeOffset($student->DateUpdated);
            }
            $new->created_at = $dateCreated ?? now();
            $new->updated_at = $this->parseDateTimeOffset($student->DateUpdated);

            $new->save();
            // Only create user if real email exists
            if ($hasRealEmail) {

                $checkuser = Newuser::where('email', $useremail)->first();

                if (!$checkuser) {
                    $user = new Newuser();
                    $user->uuid = Str::uuid()->toString();
                    $user->accounttype_id = 3;
                    $user->name = $student->Name;
                    $user->surname = $student->Surname;
                    $user->email = $useremail;
                    $user->phone = $student->Cellphone;
                    $user->password = $student->RawPassword
                        ? bcrypt($student->RawPassword)
                        : bcrypt('defaultpassword');

                    $user->save();

                    // Link user to new student
                    $new->user_id = $user->id;
                    $new->save();
                }
            $counter++;
            $this->info("Student {$counter} imported: {$student->RegistrationNumber}");
        }

        $this->info("✅ Import Completed: {$counter} students imported.");
    }
    }
    // Parse DOB
    protected function parseDate($dateString)
    {
        if (! $dateString) {
            return null;
        }
        $dateString = trim($dateString);

        if ($dateString == '0001-01-01' || $dateString == '0001-01-01 00:00:00') {
            return null;
        }

        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $dateString, $m)) {
            return "{$m[3]}-".str_pad($m[2], 2, '0', STR_PAD_LEFT).'-'.str_pad($m[1], 2, '0', STR_PAD_LEFT);
        }

        try {
            return Carbon::parse($dateString)->format('Y-m-d');
        } catch (\Exception $e) {
            return null;
        }
    }

    // ✅ Clean C# DateTime.MinValue
    protected function cleanDate($date)
    {
        if (! $date) {
            return null;
        }

        $date = trim((string) $date);

        if ($date == '0001-01-01 00:00:00' || $date == '0001-01-01') {
            return null;
        }

        try {
            return Carbon::parse($date)->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            return null;
        }
    }

    // Parse C# DateTimeOffset to Laravel timestamp
    protected function parseDateTimeOffset($date)
    {
        if (! $date) {
            return null;
        }

        $date = trim((string) $date);

        // Handle C# DateTimeOffset JSON format: /Date(1234567890000+0200)/
        if (preg_match('/^\/Date\((-?\d+)([+-]\d{4})?\)\/$/', $date, $matches)) {
            $timestamp = (int) $matches[1];
            // C# timestamp is in milliseconds, convert to seconds
            $timestamp = $timestamp / 1000;

            return Carbon::createFromTimestamp($timestamp)->format('Y-m-d H:i:s');
        }

        // Handle ISO 8601 format with offset: 2024-01-15T10:30:00+02:00
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $date)) {
            try {
                return Carbon::parse($date)->format('Y-m-d H:i:s');
            } catch (\Exception $e) {
                return null;
            }
        }

        // Fall back to cleanDate for standard formats
        return $this->cleanDate($date);
    }
}
