<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::view('/dashboard', 'dashboard')
    ->middleware(['auth', 'verified', 'active'])
    ->name('dashboard');

Route::livewire('/staff', 'staff.index')
    ->middleware(['auth', 'verified', 'active', 'admin'])
    ->name('staff.index');

require __DIR__.'/settings.php';
