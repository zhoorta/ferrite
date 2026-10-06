<?php

use App\Http\Controllers\NodeFileController;
use App\Http\Controllers\ShareFileController;
use App\Http\Controllers\UploadController;
use App\Http\Middleware\SharePageHeaders;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

// Public share links. Node ids must be numeric so they never clash with the action segments.
Route::middleware(['throttle:120,1', SharePageHeaders::class])->prefix('s/{token}')->group(function () {
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

    Route::get('nodes/{node}/download', [NodeFileController::class, 'download'])->name('nodes.download');
    Route::get('nodes/{node}/preview', [NodeFileController::class, 'preview'])->name('nodes.preview');
    Route::get('nodes/{node}/thumbnail', [NodeFileController::class, 'thumbnail'])->name('nodes.thumbnail');
    Route::get('nodes/{node}/zip', [NodeFileController::class, 'zip'])->name('nodes.zip');

    Route::livewire('admin/storage', 'pages::admin.disks')->middleware('can:admin')->name('admin.storage');
    Route::livewire('search', 'pages::files.search')->name('search');
    Route::livewire('activity', 'pages::files.activity')->name('activity');
    Route::livewire('shared', 'pages::files.shared')->name('shared');
    Route::livewire('trash', 'pages::files.trash')->name('trash');
});

require __DIR__.'/settings.php';
