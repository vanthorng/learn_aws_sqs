<?php

use App\Http\Controllers\Api\InvoiceImportController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/teams/{team:slug}')
    ->middleware(['team-api-token', 'throttle:30,1'])
    ->group(function (): void {
        Route::post('imports', [InvoiceImportController::class, 'store']);
        Route::get('imports/{invoiceImport}', [InvoiceImportController::class, 'show']);
    });
