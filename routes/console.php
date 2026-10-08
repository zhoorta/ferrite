<?php

use App\Support\Demo;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('uploads:prune')->daily();
Schedule::command('activity:prune')->daily();
Schedule::command('trash:purge')->daily();
Schedule::command('search:prune')->daily();
Schedule::command('demo:prune')->everyTenMinutes()->when(fn () => Demo::enabled());
