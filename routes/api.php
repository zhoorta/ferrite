<?php

use App\Http\Controllers\Api\ConnectController;
use App\Http\Controllers\Api\FileController;
use App\Http\Controllers\Api\RootController;
use App\Http\Controllers\Api\UploadController;
use Illuminate\Support\Facades\Route;

// Without a token: an app checks an address, then swaps the code from the browser approval for a token (docs/api.md).
Route::get('v1/ping', [ConnectController::class, 'ping'])->middleware('throttle:60,1');
Route::post('v1/connect/token', [ConnectController::class, 'token'])->middleware('throttle:20,1');

// Token API for scripts and other apps (docs/api.md). Bearer tokens only; no session, no cookies.
Route::prefix('v1')->middleware(['auth:sanctum', 'api.token:read', 'throttle:api-read'])->group(function () {
    Route::get('root', [RootController::class, 'show']);
    Route::get('files', [FileController::class, 'index']);
    Route::get('files/{node}/content', [FileController::class, 'content'])->withoutMiddleware('throttle:api-read')->middleware('throttle:api-content');
    // A grid of thumbnails is a burst of requests, like content.
    Route::get('files/{node}/thumbnail', [FileController::class, 'thumbnail'])->withoutMiddleware('throttle:api-read')->middleware('throttle:api-content');
});

// Trashing needs a token with the trash ability; it only moves to the trash, deleting for good is the separate /trash routes below.
Route::delete('v1/files/{node}', [FileController::class, 'trash'])->middleware(['auth:sanctum', 'api.token:trash', 'throttle:api-upload']);

// Listing the trash (`GET files?trashed=1`, checked in the controller) and restoring need the trash ability; purging never goes through a token.
Route::post('v1/files/{node}/restore', [FileController::class, 'restore'])->middleware(['auth:sanctum', 'api.token:trash', 'throttle:api-upload']);

// Deleting from the trash for good also needs the trash ability: it cannot be undone, so apps ask first.
Route::delete('v1/trash/{node}', [FileController::class, 'purge'])->middleware(['auth:sanctum', 'api.token:trash', 'throttle:api-upload']);
Route::delete('v1/trash', [FileController::class, 'emptyTrash'])->middleware(['auth:sanctum', 'api.token:trash', 'throttle:api-upload']);

// Creating a folder needs a write token (it only adds, and never replaces).
Route::post('v1/files', [FileController::class, 'store'])->middleware(['auth:sanctum', 'api.token:write', 'throttle:api-upload']);

// Moving a file or renaming a file or folder inside the token's folder needs a write token (it adds nothing and destroys nothing, and never replaces).
Route::patch('v1/files/{node}', [FileController::class, 'update'])->middleware(['auth:sanctum', 'api.token:write', 'throttle:api-upload']);

// Uploads need a write token; one request per chunk plus polling, so the limits are higher than for reads.
Route::prefix('v1/uploads')->middleware(['auth:sanctum', 'api.token:write'])->group(function () {
    Route::post('/', [UploadController::class, 'start'])->middleware('throttle:api-upload-start');
    Route::middleware('throttle:api-upload')->group(function () {
        Route::get('{upload}', [UploadController::class, 'status']);
        Route::patch('{upload}', [UploadController::class, 'append']);
        Route::delete('{upload}', [UploadController::class, 'cancel']);
    });
});
