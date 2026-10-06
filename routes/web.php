<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::redirect('dashboard', 'files')->name('dashboard');

    Route::livewire('files/{folder?}', 'pages::files.browser')->name('files');
    Route::livewire('trash', 'pages::files.trash')->name('trash');
});

require __DIR__.'/settings.php';
