<?php

use App\Http\Controllers\Api\RootController;
use Illuminate\Support\Facades\Route;

// Token API for scripts and other apps (docs/api.md). Bearer tokens only; no session, no cookies.
Route::prefix('v1')->middleware(['auth:sanctum', 'api.token:read', 'throttle:api-read'])->group(function () {
    Route::get('root', [RootController::class, 'show']);
});
