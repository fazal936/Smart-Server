<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')
    ->name('home');

Route::view('/dashboard', 'dashboard')
    ->middleware(['auth', 'verified', 'active'])
    ->name('dashboard');

Route::livewire('/staff', 'staff.index')
    ->middleware(['auth', 'verified', 'active', 'admin'])
    ->name('staff.index');

Route::livewire('/service-templates', 'service-templates.index')
    ->middleware(['auth', 'verified', 'active', 'admin'])
    ->name('service-templates.index');

Route::livewire('/document-requests', 'document-requests.index')
    ->middleware(['auth', 'verified', 'active'])
    ->name('document-requests.index');

require __DIR__.'/settings.php';
