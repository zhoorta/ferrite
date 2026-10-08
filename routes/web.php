<?php

use App\Http\Controllers\DemoController;
use App\Http\Controllers\DropboxUploadController;
use App\Http\Controllers\NodeFileController;
use App\Http\Controllers\ShareFileController;
use App\Http\Controllers\UploadController;
use App\Http\Middleware\DisableInDemo;
use App\Http\Middleware\SharePageHeaders;
use App\Support\Registration;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (Registration::needsSetup()) {
        return redirect()->route('register');
    }

    if (config('ferrite.landing')) {
        return view('landing');
    }

    return redirect()->route('files');
})->name('home');

Route::post('demo', DemoController::class)->middleware(['guest', 'throttle:6,60'])->name('demo.start');

// Public share links. Node ids must be numeric so they never clash with the action segments.
// Guest uploads through a drop-box link: more requests than a page view (one per chunk, plus polling).
Route::middleware([DisableInDemo::class, SharePageHeaders::class])->prefix('s/{token}/uploads')->group(function () {
    Route::post('/', [DropboxUploadController::class, 'start'])->middleware('throttle:60,1')->name('share.uploads.store');
    Route::middleware('throttle:1200,1')->group(function () {
        Route::get('{upload}', [DropboxUploadController::class, 'status'])->name('share.uploads.show');
        Route::patch('{upload}', [DropboxUploadController::class, 'append'])->name('share.uploads.update');
        Route::delete('{upload}', [DropboxUploadController::class, 'cancel'])->name('share.uploads.destroy');
    });
});

Route::middleware([DisableInDemo::class, 'throttle:120,1', SharePageHeaders::class])->prefix('s/{token}')->group(function () {
    Route::get('download/{node?}', [ShareFileController::class, 'download'])->whereNumber('node')->name('share.download');
    Route::get('preview/{node}', [ShareFileController::class, 'preview'])->whereNumber('node')->name('share.preview');
    Route::get('thumbnail/{node}', [ShareFileController::class, 'thumbnail'])->whereNumber('node')->name('share.thumbnail');
    Route::get('zip/{node?}', [ShareFileController::class, 'zip'])->whereNumber('node')->name('share.zip');
    Route::livewire('{node?}', 'pages::share.show')->whereNumber('node')->name('share.show');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::redirect('dashboard', 'files')->name('dashboard');

    Route::livewire('files/{folder?}', 'pages::files.browser')->name('files');
    Route::post('uploads', [UploadController::class, 'store'])->name('uploads.store');
    Route::get('uploads/{upload}', [UploadController::class, 'show'])->name('uploads.show');
    Route::patch('uploads/{upload}', [UploadController::class, 'update'])->name('uploads.update');
    Route::delete('uploads/{upload}', [UploadController::class, 'destroy'])->name('uploads.destroy');

    Route::get('nodes/zip', [NodeFileController::class, 'zipSelection'])->name('nodes.zip-selection');
    Route::get('nodes/{node}/download', [NodeFileController::class, 'download'])->name('nodes.download');
    Route::get('nodes/{node}/preview', [NodeFileController::class, 'preview'])->name('nodes.preview');
    Route::get('nodes/{node}/thumbnail', [NodeFileController::class, 'thumbnail'])->name('nodes.thumbnail');
    Route::get('nodes/{node}/zip', [NodeFileController::class, 'zip'])->name('nodes.zip');

    Route::livewire('admin/users', 'pages::admin.users')->middleware('can:admin')->name('admin.users');
    Route::livewire('admin/storage', 'pages::admin.disks')->middleware('can:admin')->name('admin.storage');
    Route::livewire('search', 'pages::files.search')->name('search');
    Route::livewire('activity', 'pages::files.activity')->name('activity');
    Route::livewire('favorites', 'pages::files.favorites')->name('favorites');
    Route::livewire('usage', 'pages::files.usage')->name('usage');
    Route::livewire('shared', 'pages::files.shared')->name('shared');
    Route::livewire('trash', 'pages::files.trash')->name('trash');
});

require __DIR__.'/settings.php';
