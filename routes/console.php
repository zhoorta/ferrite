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
// The integrity scrub: reads back this many blobs a night, the least recently verified first, and logs an error
// when one no longer matches its sha256 (docs/storage-check.md). Off until FERRITE_SCRUB_BLOBS is set.
Schedule::command('ferrite:storage:check', ['--verify' => true, '--limit' => (int) config('ferrite.scrub_blobs')])->dailyAt('03:30')->when(fn () => (int) config('ferrite.scrub_blobs') > 0);
Schedule::command('demo:prune')->everyTenMinutes()->when(fn () => Demo::enabled());
