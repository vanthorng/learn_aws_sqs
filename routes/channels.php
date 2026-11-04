<?php

use App\Models\InvoiceImport;
use App\Models\QuickbooksOperation;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('import.{importId}', function ($user, string $importId) {
    $import = InvoiceImport::find($importId);

    return $import !== null && $user->belongsToTeam($import->team);
});

Broadcast::channel('qbo-operation.{operationId}', function ($user, string $operationId) {
    $operation = QuickbooksOperation::find($operationId);

    return $operation !== null && $user->belongsToTeam($operation->team);
});
