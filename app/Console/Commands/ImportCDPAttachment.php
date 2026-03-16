<?php

namespace App\Console\Commands;
use App\Models\CustomerCPD;
use App\Models\NewCustomerCPDAttachment;
use Illuminate\Support\Str;
use Carbon\Carbon;

use Illuminate\Console\Command;

class ImportCDPAttachment extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:cdpattachment';

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
       $countnew = NewCustomerCPDAttachment::count();
        $cpds = [];
        if ($countnew > 0) {
            $cpds = CustomerCPD::where('id', '>', $countnew)->get();
        } else {
            $cpds  =  CustomerCPD::where('RenewalPeriod', 2026)->get();
        }

         $this->info('Total Customer attachment Found: '.$cpds->count());
        $counter = 0;
        foreach ($cpds as $cpd) {
             $new = new NewCustomerCPDAttachment;
            $new->id = $cpd->Id;
            $new->mycdp_id = $cpd->Id;
            $new->type = 'PROGRAMME';
            $new->file = $cpd->Path;
            $new->created_at = now();
            $new->updated_at =  now();

            $new->save();

              $counter++;
            $this->info("attachment {$counter} imported");
        }

        $this->info("CPD attachment Import Completed!");
    }
}
