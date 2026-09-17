<?php

use App\Enums\InvoiceImportStatus;
use App\Enums\TeamRole;
use App\Jobs\ExportQuickbooksInvoices;
use App\Jobs\DeleteQuickbooksInvoices;
use App\Jobs\VoidQuickbooksInvoices;
use App\Models\InvoiceImport;
use App\Models\InvoiceRecord;
use App\Models\QuickbooksConnection;
use App\Models\QuickbooksOperation;
use App\Models\Team;
use App\Models\User;
use App\Models\QuickbooksOperationItem;
use App\Services\QuickBooks\QuickBooksClient;
use App\Services\QuickBooks\QuickbooksExportWriter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

function quickbooksDataTeam(User $user): Team
{
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    QuickbooksConnection::create([
        'team_id' => $team->id, 'realm_id' => 'realm-data', 'access_token' => 'token', 'refresh_token' => 'refresh',
        'access_token_expires_at' => now()->addHour(), 'refresh_token_expires_at' => now()->addDays(30), 'environment' => 'sandbox',
    ]);

    return $team;
}

function quickbooksDataRecord(Team $team, User $user, string $qboId = 'qbo-invoice-1'): InvoiceRecord
{
    $import = InvoiceImport::create([
        'team_id' => $team->id, 'uploaded_by' => $user->id, 'status' => InvoiceImportStatus::Completed,
        'storage_disk' => 'local', 'storage_path' => 'imports/data.xlsx', 'original_filename' => 'Data.xlsx',
        'file_hash' => str_repeat('d', 64), 'file_size' => 12,
    ]);

    return InvoiceRecord::create([
        'invoice_import_id' => $import->id, 'source_row_number' => 2, 'doc_number' => 'INV-DATA-1', 'customer' => 'Example customer',
        'txn_date' => now()->toDateString(), 'line_item' => 'Consulting', 'line_amount' => 200, 'payload' => [], 'qbo_invoice_id' => $qboId,
    ]);
}

test('an import manager can queue a filtered QuickBooks export', function () {
    Queue::fake();
    $user = User::factory()->create();
    $team = quickbooksDataTeam($user);

    $this->actingAs($user)->from(route('quickbooks.data.index', $team))
        ->post(route('quickbooks.data.exports.store', $team), [
            'from_date' => now()->subDays(7)->toDateString(), 'to_date' => now()->toDateString(), 'statuses' => ['open', 'paid'],
        ])->assertRedirect(route('quickbooks.data.index', $team));

    $operation = QuickbooksOperation::firstOrFail();
    expect($operation->type)->toBe('export')->and($operation->filters['statuses'])->toBe(['open', 'paid']);
    Queue::assertPushed(ExportQuickbooksInvoices::class, fn ($job) => $job->operationId === $operation->id);
});

test('an import manager can browse live QBO invoices that are not locally imported', function () {
    $user = User::factory()->create();
    $team = quickbooksDataTeam($user);
    Http::fake(fn () => Http::response(['QueryResponse' => ['Invoice' => [[
        'Id' => 'qbo-external-1', 'DocNumber' => 'EXT-100', 'CustomerRef' => ['name' => 'External customer'],
        'TxnDate' => now()->toDateString(), 'TotalAmt' => 125, 'Balance' => 125,
    ]]]]));

    $this->actingAs($user)->getJson(route('quickbooks.data.invoices.index', [
        'current_team' => $team, 'from_date' => now()->subDay()->toDateString(), 'to_date' => now()->toDateString(),
        'statuses' => ['open'], 'customer' => 'external', 'doc_number' => '100',
    ]))->assertOk()->assertJsonPath('data.0.qboInvoiceId', 'qbo-external-1')
        ->assertJsonPath('data.0.docNumber', 'EXT-100')->assertJsonPath('data.0.canVoid', true)
        ->assertJsonPath('meta.per_page', 100);
});

test('voiding requires exact confirmation and creates one item per QBO invoice', function () {
    Queue::fake();
    $user = User::factory()->create();
    $team = quickbooksDataTeam($user);

    $this->actingAs($user)->post(route('quickbooks.data.voids.store', $team), [
        'confirmation' => 'void', 'qbo_invoice_ids' => ['qbo-external'],
    ])->assertSessionHasErrors('confirmation');

    $this->actingAs($user)->from(route('quickbooks.data.index', $team))->post(route('quickbooks.data.voids.store', $team), [
        'confirmation' => 'VOID', 'qbo_invoice_ids' => ['qbo-external'],
    ])->assertRedirect(route('quickbooks.data.index', $team));

    $operation = QuickbooksOperation::where('type', 'void')->firstOrFail();
    expect($operation->items)->toHaveCount(1)->and($operation->items->first()->qbo_invoice_id)->toBe('qbo-external')->and($operation->total_count)->toBe(1);
    Queue::assertPushed(VoidQuickbooksInvoices::class, fn ($job) => $job->operationId === $operation->id);
});

test('deletion requires exact confirmation and allows an external QBO invoice', function () {
    Queue::fake();
    $user = User::factory()->create();
    $team = quickbooksDataTeam($user);

    $this->actingAs($user)->post(route('quickbooks.data.deletions.store', $team), [
        'confirmation' => 'DELETE!', 'qbo_invoice_ids' => ['qbo-external-delete'],
    ])->assertSessionHasErrors('confirmation');

    $this->actingAs($user)->from(route('quickbooks.data.index', $team))->post(route('quickbooks.data.deletions.store', $team), [
        'confirmation' => 'DELETE', 'qbo_invoice_ids' => ['qbo-external-delete'],
    ])->assertRedirect(route('quickbooks.data.index', $team));

    $operation = QuickbooksOperation::where('type', 'delete')->firstOrFail();
    expect($operation->items)->toHaveCount(1)->and($operation->items->first()->qbo_invoice_id)->toBe('qbo-external-delete');
    Queue::assertPushed(DeleteQuickbooksInvoices::class, fn ($job) => $job->operationId === $operation->id);
});

test('completed export downloads are private to the owning team', function () {
    Storage::fake('local');
    $user = User::factory()->create();
    $team = quickbooksDataTeam($user);
    $operation = QuickbooksOperation::create([
        'team_id' => $team->id, 'requested_by' => $user->id, 'type' => 'export', 'status' => 'completed',
        'storage_disk' => 'local', 'storage_path' => 'quickbooks-exports/test.xlsx', 'download_filename' => 'invoices.xlsx', 'expires_at' => now()->addDay(),
    ]);
    Storage::disk('local')->put($operation->storage_path, 'xlsx-content');

    $this->actingAs($user)->get(route('quickbooks.data.exports.download', [$team, $operation]))->assertOk();

    $other = User::factory()->create();
    $otherTeam = quickbooksDataTeam($other);
    $this->actingAs($other)->get(route('quickbooks.data.exports.download', [$otherTeam, $operation]))->assertNotFound();
});

test('export job writes a private two-sheet XLSX workbook', function () {
    Storage::fake('local');
    config()->set('imports.disk', 'local');
    Notification::fake();
    $user = User::factory()->create();
    $team = quickbooksDataTeam($user);
    $operation = QuickbooksOperation::create([
        'team_id' => $team->id, 'requested_by' => $user->id, 'type' => 'export', 'status' => 'queued',
        'filters' => ['from_date' => now()->subDay()->toDateString(), 'to_date' => now()->toDateString(), 'statuses' => ['open']],
    ]);
    Http::fake(function () {
        return Http::response([
            'QueryResponse' => [
                'Invoice' => [[
                    'Id' => 'qbo-export-1', 'DocNumber' => 'EXP-1', 'CustomerRef' => ['name' => 'Export customer'],
                    'TxnDate' => now()->toDateString(), 'TotalAmt' => 50, 'Balance' => 50,
                    'Line' => [['Description' => 'Service', 'Amount' => 50, 'SalesItemLineDetail' => ['ItemRef' => ['name' => 'Services'], 'Qty' => 1, 'UnitPrice' => 50]]],
                ]],
            ],
        ]);
    });

    (new ExportQuickbooksInvoices($operation->id))->handle(new QuickBooksClient, new QuickbooksExportWriter);

    $operation->refresh();
    expect($operation->status)->toBe('completed')->and($operation->storage_path)->not->toBeNull();
    Storage::disk('local')->assertExists($operation->storage_path);
    $workbook = new \ZipArchive;
    $workbook->open(Storage::disk('local')->path($operation->storage_path));
    expect($workbook->getFromName('xl/worksheets/sheet1.xml'))->toContain('EXP-1')
        ->and($workbook->getFromName('xl/worksheets/sheet2.xml'))->toContain('Service');
    $workbook->close();
});

test('void job retrieves a SyncToken and marks every linked local row voided', function () {
    Notification::fake();
    $user = User::factory()->create();
    $team = quickbooksDataTeam($user);
    $first = quickbooksDataRecord($team, $user, 'qbo-void-1');
    $second = quickbooksDataRecord($team, $user, 'qbo-void-1');
    $operation = QuickbooksOperation::create(['team_id' => $team->id, 'requested_by' => $user->id, 'type' => 'void', 'status' => 'queued', 'total_count' => 1]);
    QuickbooksOperationItem::create(['quickbooks_operation_id' => $operation->id, 'qbo_invoice_id' => 'qbo-void-1']);
    Http::fake(function ($request) {
        return $request->method() === 'GET'
            ? Http::response(['Invoice' => ['Id' => 'qbo-void-1', 'DocNumber' => 'VOID-1', 'CustomerRef' => ['name' => 'Void customer'], 'SyncToken' => '7', 'Balance' => 200]])
            : Http::response(['Invoice' => ['Id' => 'qbo-void-1', 'TxnStatus' => 'Voided']]);
    });

    (new VoidQuickbooksInvoices($operation->id))->handle(new QuickBooksClient);

    expect($operation->fresh()->status)->toBe('completed')
        ->and($first->fresh()->qbo_voided_at)->not->toBeNull()
        ->and($second->fresh()->qbo_voided_at)->not->toBeNull();
    Http::assertSent(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), 'operation=void') && $request['SyncToken'] === '7');
});

test('void job records an external QBO invoice without a local import record', function () {
    Notification::fake();
    $user = User::factory()->create();
    $team = quickbooksDataTeam($user);
    $operation = QuickbooksOperation::create(['team_id' => $team->id, 'requested_by' => $user->id, 'type' => 'void', 'status' => 'queued', 'total_count' => 1]);
    $item = QuickbooksOperationItem::create(['quickbooks_operation_id' => $operation->id, 'qbo_invoice_id' => 'qbo-external-void']);
    Http::fake(function ($request) {
        return $request->method() === 'GET'
            ? Http::response(['Invoice' => ['Id' => 'qbo-external-void', 'DocNumber' => 'EXT-VOID', 'CustomerRef' => ['name' => 'External customer'], 'SyncToken' => '8', 'Balance' => 55]])
            : Http::response(['Invoice' => ['Id' => 'qbo-external-void', 'TxnStatus' => 'Voided']]);
    });

    (new VoidQuickbooksInvoices($operation->id))->handle(new QuickBooksClient);

    expect($operation->fresh()->status)->toBe('completed')
        ->and($item->fresh()->status)->toBe('completed')
        ->and($item->fresh()->doc_number)->toBe('EXT-VOID')
        ->and($item->fresh()->customer)->toBe('External customer');
});

test('delete job retrieves a SyncToken and records an external QBO invoice deletion', function () {
    Notification::fake();
    $user = User::factory()->create();
    $team = quickbooksDataTeam($user);
    $operation = QuickbooksOperation::create(['team_id' => $team->id, 'requested_by' => $user->id, 'type' => 'delete', 'status' => 'queued', 'total_count' => 1]);
    $item = QuickbooksOperationItem::create(['quickbooks_operation_id' => $operation->id, 'qbo_invoice_id' => 'qbo-external-delete']);
    Http::fake(function ($request) {
        return $request->method() === 'GET'
            ? Http::response(['Invoice' => ['Id' => 'qbo-external-delete', 'DocNumber' => 'EXT-DELETE', 'CustomerRef' => ['name' => 'Delete customer'], 'SyncToken' => '9', 'Balance' => 99]])
            : Http::response(['Invoice' => ['Id' => 'qbo-external-delete']]);
    });

    (new DeleteQuickbooksInvoices($operation->id))->handle(new QuickBooksClient);

    expect($operation->fresh()->status)->toBe('completed')
        ->and($item->fresh()->status)->toBe('completed')
        ->and($item->fresh()->doc_number)->toBe('EXT-DELETE')
        ->and($item->fresh()->customer)->toBe('Delete customer');
    Http::assertSent(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), 'operation=delete') && $request['SyncToken'] === '9');
});
