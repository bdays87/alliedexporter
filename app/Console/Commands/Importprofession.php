<?php

namespace App\Console\Commands;

use App\Models\Newprofession;
use App\Models\Profession;
use Illuminate\Console\Command;

class Importprofession extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:importprofession';

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
        $professions = Profession::all();
        $this->info('Total Professions Found: ' . $professions->count());

        // Pre-load existing professions keyed by name+prefix — eliminates one SELECT per row
        $existing = Newprofession::all()
            ->keyBy(fn($p) => $p->name . '|' . $p->prefix);

        $rows    = [];
        $skipped = 0;

        foreach ($professions as $profession) {
            $key = $profession->Name . '|' . $profession->Prefix;

            if (isset($existing[$key])) {
                $this->warn('Already exists — ID: ' . $profession->Id . ' | ' . $profession->Description . ' / ' . $profession->Prefix);
                $skipped++;
                continue;
            }

            $rows[] = [
                'id'     => $profession->Id,
                'name'   => $profession->Name,
                'prefix' => $profession->Prefix,
            ];

            // Mark as seen so duplicates within the source are also skipped
            $existing[$key] = true;
        }

        if (!empty($rows)) {
            try {
                Newprofession::insertOrIgnore($rows);
                $this->info('Imported ' . count($rows) . ' professions. Skipped ' . $skipped . '.');
            } catch (\Exception $e) {
                $this->error('Bulk insert failed, falling back row-by-row: ' . $e->getMessage());
                $counter = 0;
                foreach ($rows as $row) {
                    try {
                        Newprofession::insertOrIgnore([$row]);
                        $counter++;
                    } catch (\Exception $ex) {
                        $this->error('Failed Profession ID ' . $row['id'] . ': ' . $ex->getMessage());
                    }
                }
                $this->info('Imported ' . $counter . ' professions. Skipped ' . $skipped . '.');
            }
        } else {
            $this->info('Nothing to import. Skipped ' . $skipped . '.');
        }
    }
}
