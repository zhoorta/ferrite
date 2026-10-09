<?php

use App\Http\Controllers\Api\FileController;
use App\Http\Controllers\Api\RootController;
use App\Http\Controllers\Api\UploadController;
use Illuminate\Support\Facades\Route;

// Token API for scripts and other apps (docs/api.md). Bearer tokens only; no session, no cookies.
Route::prefix('v1')->middleware(['auth:sanctum', 'api.token:read', 'throttle:api-read'])->group(function () {
    Route::get('root', [RootController::class, 'show']);
    Route::get('files', [FileController::class, 'index']);
    Route::get('files/{node}/content', [FileController::class, 'content'])->withoutMiddleware('throttle:api-read')->middleware('throttle:api-content');
});

// Uploads need a write token; one request per chunk plus polling, so the limits are higher than for reads.
Route::prefix('v1/uploads')->middleware(['auth:sanctum', 'api.token:write'])->group(function () {
    Route::post('/', [UploadController::class, 'start'])->middleware('throttle:api-upload-start');
    Route::middleware('throttle:api-upload')->group(function () {
        Route::get('{upload}', [UploadController::class, 'status']);
        Route::patch('{upload}', [UploadController::class, 'append']);
        Route::delete('{upload}', [UploadController::class, 'cancel']);
    });
});
