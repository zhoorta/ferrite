<?php

use App\Http\Controllers\UploadController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::redirect('dashboard', 'files')->name('dashboard');

    Route::livewire('files/{folder?}', 'pages::files.browser')->name('files');
    Route::post('uploads', [UploadController::class, 'store'])->name('uploads.store');
    Route::get('uploads/{upload}', [UploadController::class, 'show'])->name('uploads.show');
    Route::patch('uploads/{upload}', [UploadController::class, 'update'])->name('uploads.update');
    Route::delete('uploads/{upload}', [UploadController::class, 'destroy'])->name('uploads.destroy');

    Route::livewire('trash', 'pages::files.trash')->name('trash');
});

require __DIR__.'/settings.php';
