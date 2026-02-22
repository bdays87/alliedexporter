<?php

namespace App\Console\Commands;

use DB;
use Illuminate\Console\Command;
use Carbon\Carbon;
class ImportStudentProfessionlist extends Command
{
    protected $signature = 'app:import-student-professionlist';

    protected $description = 'Import student professions from C# DB to PHP DB';

    public function handle()
    {
        $this->info('Starting import...');

        // SOURCE DATABASE = C# DB
        $students = DB::connection('mysql')->table('students')
            ->whereNotNull('ProfessionId')
            ->selectRaw("
                Id AS student_id,
                ProfessionId AS profession_id,
                College AS college,
                ProgrammeName AS programme,
                SUBSTRING(StartDate,1,4) AS startyear,
                SUBSTRING(EndDate,1,4) AS endyear,
                ApprovalStatus AS approvalstatus,
                DateCreated AS DateCreated,
                DateUpdated AS DateUpdated,

                CASE
                    WHEN ProgrammeName LIKE '%PhD%' THEN 1
                    WHEN ProgrammeName LIKE '%Master%' THEN 2
                    WHEN ProgrammeName LIKE '%Bachelor%' OR ProgrammeName LIKE '%Bach%' THEN 3
                    WHEN ProgrammeName LIKE '%Diploma%' THEN 5
                    WHEN ProgrammeName LIKE '%Cert%' THEN 6
                    ELSE NULL
                END AS qualificationlevel_id
            ")
            ->get();

        $this->info('Total Records Found: '.$students->count());

        $counter = 0;
        $skipped = 0;

        foreach ($students as $s) {

            // Skip if no profession
            if (! $s->profession_id) {
                $skipped++;

                continue;
            }

            // CHECK if student exists in PHP DB (FK SAFE)
            $studentExists = DB::connection('mysql')->table('students')
                ->where('id', $s->student_id)
                ->exists();

            if (! $studentExists) {
                $skipped++;

                continue;
            }

            // Prevent duplicate insert
            $already = DB::connection('mysql2')->table('studentprofessions')
                ->where('student_id', $s->student_id)
                ->where('profession_id', $s->profession_id)
                ->exists();

            if ($already) {
                continue;
            }

            if (! $s->qualificationlevel_id) {
                $skipped++;

                continue;
            }
            // INSERT INTO PHP DB
            DB::connection('mysql2')->table('studentprofessions')->insert([
                'id' => $s->student_id,
                'student_id' => $s->student_id,
                'profession_id' => $s->profession_id,
                'college' => $s->college,
                'qualificationlevel_id' => $s->qualificationlevel_id,
                'programme' => $s->programme,
                'startyear' => $s->startyear,
                'endyear' => $s->endyear,
                'status' => $s->approvalstatus,
                'created_at' => $this->parseDateTimeOffset($s->DateCreated),
                'updated_at' => $this->parseDateTimeOffset($s->DateUpdated),
            ]);

            $counter++;
        }

        $this->info("✅ Imported: $counter");
        $this->warn("⚠ Skipped: $skipped");
        $this->info('Done.');
    }

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
}
