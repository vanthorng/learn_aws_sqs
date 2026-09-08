<?php

use App\Enums\InvoiceImportStatus;
use App\Enums\TeamRole;
use App\Models\InvoiceImport;
use App\Models\Team;
use App\Models\User;
use App\Jobs\ProcessInvoiceImport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

function importTestTeam(User $user, TeamRole $role = TeamRole::Owner): Team
{
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);

    return $team;
}

function makeInvoiceImport(Team $team, User $user, InvoiceImportStatus $status = InvoiceImportStatus::Pending): InvoiceImport
{
    return InvoiceImport::create([
        'team_id' => $team->id,
        'uploaded_by' => $user->id,
        'status' => $status,
        'storage_disk' => 'local',
        'storage_path' => 'imports/test.xlsx',
        'original_filename' => 'Invoice.xlsx',
        'file_hash' => str_repeat('a', 64),
        'file_size' => 10,
    ]);
}

test('team members can view their team import workspace', function () {
    $user = User::factory()->create();
    $team = importTestTeam($user, TeamRole::Member);
    makeInvoiceImport($team, $user);

    $this->actingAs($user)->get(route('imports.index', $team))->assertOk();
});

test('members cannot upload invoice imports', function () {
    $user = User::factory()->create();
    $team = importTestTeam($user, TeamRole::Member);

    $this->actingAs($user)
        ->post(route('imports.store', $team), ['file' => UploadedFile::fake()->create('Invoice.xlsx', 10)])
        ->assertForbidden();
});

test('team members cannot inspect another teams import', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $team = importTestTeam($user);
    $otherTeam = importTestTeam($other);
    $import = makeInvoiceImport($otherTeam, $other);

    $this->actingAs($user)->get(route('imports.show', [$team, $import]))->assertNotFound();
});

test('owners can requeue a failed import from its stored file', function () {
    Queue::fake();
    Storage::fake('local');
    $user = User::factory()->create();
    $team = importTestTeam($user);
    $import = makeInvoiceImport($team, $user, InvoiceImportStatus::Failed);
    Storage::disk('local')->put($import->storage_path, 'test');

    $this->actingAs($user)->post(route('imports.retry', [$team, $import]))->assertRedirect(route('imports.index', $team));

    expect($import->fresh()->status)->toBe(InvoiceImportStatus::Pending);
    Queue::assertPushed(ProcessInvoiceImport::class, fn (ProcessInvoiceImport $job) => $job->importId === $import->id);
});

test('owners can requeue a completed import with row errors', function () {
    Queue::fake();
    Storage::fake('local');
    $user = User::factory()->create();
    $team = importTestTeam($user);
    $import = makeInvoiceImport($team, $user, InvoiceImportStatus::Completed);
    $import->update(['failed_count' => 1]);
    Storage::disk('local')->put($import->storage_path, 'test');

    $this->actingAs($user)->post(route('imports.retry', [$team, $import]))->assertRedirect(route('imports.index', $team));

    expect($import->fresh()->status)->toBe(InvoiceImportStatus::Pending);
});
