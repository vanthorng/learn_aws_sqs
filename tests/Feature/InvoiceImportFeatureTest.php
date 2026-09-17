<?php

use App\Enums\InvoiceImportStatus;
use App\Enums\TeamRole;
use App\Models\InvoiceImport;
use App\Models\Team;
use App\Models\User;
use App\Jobs\ProcessInvoiceImport;
use App\Actions\Imports\DispatchScheduledImports;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use App\Models\ScheduledImport;

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

function makeScheduledImport(Team $team, User $user, array $attributes = []): ScheduledImport
{
    return ScheduledImport::create(array_merge([
        'team_id' => $team->id,
        'created_by' => $user->id,
        'status' => 'scheduled',
        'scheduled_for' => now()->addHour(),
        'storage_disk' => 'local',
        'storage_path' => 'scheduled-imports/test.xlsx',
        'original_filename' => 'Scheduled invoices.xlsx',
        'file_hash' => str_repeat('b', 64),
        'file_size' => 10,
    ], $attributes));
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
        ->post(route('imports.store', $team))
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

test('team members can view their team schedule but not another team schedule', function () {
    $user = User::factory()->create();
    $team = importTestTeam($user, TeamRole::Member);
    $other = User::factory()->create();
    $otherTeam = importTestTeam($other);
    makeScheduledImport($team, $user);
    makeScheduledImport($otherTeam, $other);

    $this->actingAs($user)
        ->get(route('schedules.index', $team))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('schedules', 1));
});

test('owners can cancel an upcoming scheduled import', function () {
    $user = User::factory()->create();
    $team = importTestTeam($user);
    $schedule = makeScheduledImport($team, $user);

    $this->actingAs($user)
        ->delete(route('schedules.cancel', [$team, $schedule]))
        ->assertRedirect(route('schedules.index', $team));

    expect($schedule->fresh()->status)->toBe('cancelled')
        ->and($schedule->fresh()->cancelled_at)->not->toBeNull();
});

test('due scheduled imports are converted into queued imports exactly once', function () {
    Queue::fake();
    $user = User::factory()->create();
    $team = importTestTeam($user);
    $schedule = makeScheduledImport($team, $user, ['scheduled_for' => now()->subMinute(), 'auto_sync_qbo' => true]);

    expect(app(DispatchScheduledImports::class)->handle())->toBe(1);

    $schedule->refresh();
    $import = InvoiceImport::find($schedule->invoice_import_id);

    expect($schedule->status)->toBe('dispatched')
        ->and($schedule->dispatched_at)->not->toBeNull()
        ->and($import)->not->toBeNull()
        ->and($import->auto_sync_qbo)->toBeTrue();
    Queue::assertPushed(ProcessInvoiceImport::class, fn (ProcessInvoiceImport $job) => $job->importId === $import->id);
    expect(app(DispatchScheduledImports::class)->handle())->toBe(0);
});
