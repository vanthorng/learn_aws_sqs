<?php

use Illuminate\Support\Facades\Broadcast;
use App\Models\InvoiceImport;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('import.{importId}', function ($user, string $importId) {
    $import = InvoiceImport::find($importId);

    return $import !== null && $user->belongsToTeam($import->team);
});
