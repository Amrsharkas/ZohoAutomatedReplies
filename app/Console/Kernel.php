<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array<int, class-string>
     */
    protected $commands = [
        \App\Console\Commands\GenerateReplyDrafts::class,
        \App\Console\Commands\ZohoDebug::class,
    ];

    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('zoho:generate-drafts --limit=20')->everyTenMinutes();
    }
}


