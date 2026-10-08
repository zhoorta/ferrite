<?php

use App\Support\Demo;
use App\Support\Search\ContentSearch;
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
// Reads files that were never indexed: PDFs without a queue worker, or anything whose job was lost.
Schedule::command('search:index')->hourly()->when(fn () => ContentSearch::enabled());
Schedule::command('demo:prune')->everyTenMinutes()->when(fn () => Demo::enabled());
