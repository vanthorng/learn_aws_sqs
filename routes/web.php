<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InvoiceImportController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\TeamApiTokenController;
use App\Http\Controllers\ScheduledImportController;
use App\Http\Controllers\QuickBooks\QuickBooksAuthController;
use App\Http\Controllers\QuickBooks\QuickBooksSyncController;
use App\Http\Controllers\QuickBooks\QuickBooksDataController;
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

        Route::get('schedule', [ScheduledImportController::class, 'index'])->name('schedules.index');
        Route::post('schedule', [ScheduledImportController::class, 'store'])->name('schedules.store');
        Route::delete('schedule/{scheduledImport}', [ScheduledImportController::class, 'cancel'])->name('schedules.cancel');

        // QuickBooks Online Integration
        Route::get('quickbooks/connect', [QuickBooksAuthController::class, 'connect'])->name('quickbooks.connect');
        Route::get('quickbooks/callback', [QuickBooksAuthController::class, 'callback'])->name('quickbooks.callback');
        Route::delete('quickbooks/disconnect', [QuickBooksAuthController::class, 'disconnect'])->name('quickbooks.disconnect');
        Route::post('imports/{invoiceImport}/sync-qbo', [QuickBooksSyncController::class, 'sync'])->name('imports.sync-qbo');
        Route::get('quickbooks/data', [QuickBooksDataController::class, 'index'])->name('quickbooks.data.index');
        Route::get('quickbooks/data/invoices', [QuickBooksDataController::class, 'invoices'])->name('quickbooks.data.invoices.index');
        Route::post('quickbooks/data/exports', [QuickBooksDataController::class, 'createExport'])->name('quickbooks.data.exports.store');
        Route::post('quickbooks/data/voids', [QuickBooksDataController::class, 'void'])->name('quickbooks.data.voids.store');
        Route::post('quickbooks/data/deletions', [QuickBooksDataController::class, 'destroy'])->name('quickbooks.data.deletions.store');
        Route::get('quickbooks/data/operations/{operation}', [QuickBooksDataController::class, 'show'])->name('quickbooks.data.operations.show');
        Route::get('quickbooks/data/exports/{operation}/download', [QuickBooksDataController::class, 'download'])->name('quickbooks.data.exports.download');
        Route::post('api-tokens', [TeamApiTokenController::class, 'store'])->name('api-tokens.store');
        Route::delete('api-tokens/{apiToken}', [TeamApiTokenController::class, 'destroy'])->name('api-tokens.destroy');
    });

Route::middleware(['auth'])->group(function () {
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::patch('notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
    Route::patch('notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
    Route::get('callback', [QuickBooksAuthController::class, 'callback'])->name('quickbooks.global_callback');
    Route::get('quickbooks/callback', [QuickBooksAuthController::class, 'callback'])->name('quickbooks.static_callback');
    Route::post('invitations/{invitation}/accept', [TeamInvitationController::class, 'accept'])->name('invitations.accept');
    Route::delete('invitations/{invitation}', [TeamInvitationController::class, 'decline'])->name('invitations.decline');
});

require __DIR__.'/settings.php';
