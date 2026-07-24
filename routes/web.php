<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')
    ->name('home');

/*
 * Customer-facing secure upload form.
 *
 * No customer account or login is required. Access is limited by the
 * random 64-character token, and the Livewire component verifies that
 * the link is active, unexpired and permitted to receive uploads.
 *
 * Throttling reduces repeated automated attempts against this endpoint.
 */
Route::livewire('/upload/{token}', 'customer-upload.show')
    ->middleware(['throttle:30,1'])
    ->where('token', '[A-Za-z0-9]{64}')
    ->name('customer-upload.show');

/*
 * Authenticated staff dashboard.
 */
Route::view('/dashboard', 'dashboard')
    ->middleware(['auth', 'verified', 'active'])
    ->name('dashboard');

/*
 * Staff account administration is restricted to administrators.
 */
Route::livewire('/staff', 'staff.index')
    ->middleware(['auth', 'verified', 'active', 'admin'])
    ->name('staff.index');

/*
 * Service templates define available services and their default
 * document checklists. Only administrators may manage them.
 */
Route::livewire('/service-templates', 'service-templates.index')
    ->middleware(['auth', 'verified', 'active', 'admin'])
    ->name('service-templates.index');

/*
 * All active staff can access the document request workspace.
 * The component limits ordinary employees to requests assigned to
 * or created by them.
 */
Route::livewire('/document-requests', 'document-requests.index')
    ->middleware(['auth', 'verified', 'active'])
    ->name('document-requests.index');

/*
 * Route-model binding loads the selected DocumentRequest.
 * The component performs the final request-level authorization check.
 */
Route::livewire(
    '/document-requests/{documentRequest}',
    'document-requests.show'
)
    ->middleware(['auth', 'verified', 'active'])
    ->name('document-requests.show');

require __DIR__.'/settings.php';
