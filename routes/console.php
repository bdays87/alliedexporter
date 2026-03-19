<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Console\Commands\SyncAllFromAudit;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule the full sync to run daily at 15:43
Schedule::command(SyncAllFromAudit::class)->dailyAt('15:43');
