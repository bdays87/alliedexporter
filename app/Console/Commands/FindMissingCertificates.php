<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class FindMissingCertificates extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'certificates:find-missing';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Find missing certificate numbers from 1 to 1804 in the CSV list';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $csvPath = public_path('customerapplicatiok202603190041.csv');

        if (!file_exists($csvPath)) {
            $this->error('CSV file not found.');
            return;
        }

        $existingNumbers = [];
        $handle = fopen($csvPath, 'r');

        // Skip header
        fgetcsv($handle);

        while (($data = fgetcsv($handle)) !== false) {
            $existingNumbers[] = (int) $data[0];
        }

        fclose($handle);

        $total = 1804;
        $missing = [];

        for ($i = 1; $i <= $total; $i++) {
            if (!in_array($i, $existingNumbers)) {
                $missing[] = $i;
            }
        }

        if (empty($missing)) {
            $this->info('No missing certificates found.');
        } else {
            $this->info('Missing certificate numbers:');
            foreach ($missing as $num) {
                $this->line($num);
            }
        }
    }
}