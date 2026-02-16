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
        foreach ($professions as $profession) {
            $check = Newprofession::where('name', $profession->Description)->where("prefix", $profession->Prefix)->first();
            if (!$check) {
                $newprofession = new Newprofession();
                $newprofession->name = $profession->Description;
                $newprofession->prefix = $profession->Prefix;
                $newprofession->save();
                $this->info('Profession ID: ' . $profession->Id . ' Description: ' . $profession->Description . ' Prefix: ' . $profession->Prefix);
            } else {
                $this->error('Profession already exists for Profession ID: ' . $profession->Id . ' Description: ' . $profession->Description . ' Prefix: ' . $profession->Prefix);
            }
        }
    }
}
