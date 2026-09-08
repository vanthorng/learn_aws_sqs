<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InvoiceImportController;
use App\Http\Controllers\Teams\TeamInvitationController;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class])
    ->group(function () {
        Route::get('dashboard', DashboardController::class)->name('dashboard');
        Route::get('imports', [InvoiceImportController::class, 'index'])->name('imports.index');
        Route::post('imports', [InvoiceImportController::class, 'store'])->name('imports.store');
        Route::get('imports/{invoiceImport}', [InvoiceImportController::class, 'show'])->name('imports.show');
        Route::post('imports/{invoiceImport}/retry', [InvoiceImportController::class, 'retry'])->name('imports.retry');
    });

Route::middleware(['auth'])->group(function () {
    Route::post('invitations/{invitation}/accept', [TeamInvitationController::class, 'accept'])->name('invitations.accept');
    Route::delete('invitations/{invitation}', [TeamInvitationController::class, 'decline'])->name('invitations.decline');
});

require __DIR__.'/settings.php';
