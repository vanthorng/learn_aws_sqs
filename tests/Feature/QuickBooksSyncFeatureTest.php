<?php

use App\Enums\InvoiceImportStatus;
use App\Enums\TeamRole;
use App\Jobs\SyncImportToQuickBooks;
use App\Models\InvoiceImport;
use App\Models\InvoiceRecord;
use App\Models\QuickbooksConnection;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function makeTeamWithQbo(User $user, TeamRole $role = TeamRole::Owner): array
{
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);

    $connection = QuickbooksConnection::create([
        'team_id' => $team->id,
        'realm_id' => '1234567890',
        'access_token' => 'mock-token',
        'refresh_token' => 'mock-refresh',
        'access_token_expires_at' => now()->addHour(),
        'refresh_token_expires_at' => now()->addDays(30),
        'company_name' => 'Sandbox Cambodia Co.',
        'environment' => 'sandbox',
    ]);

    return [$team, $connection];
}

test('team owner can initiate quickbooks connect', function () {
    config([
        'quickbooks.client_id' => 'test-client',
        'quickbooks.client_secret' => 'test-secret',
    ]);

    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $response = $this->actingAs($user)->get(route('quickbooks.connect', $team));

    $response->assertRedirect();
    expect(session('qbo_oauth_state'))->not()->toBeEmpty();
});

test('team owner can complete quickbooks oauth callback', function () {
    config([
        'quickbooks.client_id' => 'test-client',
        'quickbooks.client_secret' => 'test-secret',
    ]);

    Http::fake([
        'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer' => Http::response([
            'access_token' => 'auth-access-token',
            'refresh_token' => 'auth-refresh-token',
            'expires_in' => 3600,
            'x_refresh_token_expires_in' => 8726400,
        ], 200),
        'https://sandbox-quickbooks.api.intuit.com/v3/company/98765/companyinfo/98765' => Http::response([
            'CompanyInfo' => ['CompanyName' => 'Khmer Trading Ltd.'],
        ], 200),
    ]);

    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $state = 'test-state-string';
    session(['qbo_oauth_state' => $state]);

    $response = $this->actingAs($user)->get(route('quickbooks.callback', [
        'current_team' => $team,
        'state' => $state,
        'code' => 'valid-auth-code',
        'realmId' => '98765',
    ]));

    $response->assertRedirect(route('imports.index', $team));

    $connection = $team->fresh()->quickbooksConnection;
    expect($connection)->not()->toBeNull()
        ->and($connection->realm_id)->toBe('98765')
        ->and($connection->company_name)->toBe('Khmer Trading Ltd.');
});

test('team owner can disconnect quickbooks', function () {
    $user = User::factory()->create();
    [$team, $connection] = makeTeamWithQbo($user);

    $response = $this->actingAs($user)->delete(route('quickbooks.disconnect', $team));

    $response->assertRedirect(route('imports.index', $team));
    expect($team->fresh()->quickbooksConnection)->toBeNull();
});

test('team owner can dispatch quickbooks sync for completed import', function () {
    Queue::fake();

    $user = User::factory()->create();
    [$team, $connection] = makeTeamWithQbo($user);

    $import = InvoiceImport::create([
        'team_id' => $team->id,
        'uploaded_by' => $user->id,
        'status' => InvoiceImportStatus::Completed,
        'storage_disk' => 'local',
        'storage_path' => 'imports/test.xlsx',
        'original_filename' => 'Invoice.xlsx',
        'file_hash' => str_repeat('b', 64),
        'file_size' => 20,
    ]);

    $response = $this->actingAs($user)->post(route('imports.sync-qbo', [$team, $import]));

    $response->assertRedirect();
    Queue::assertPushed(SyncImportToQuickBooks::class, fn ($job) => $job->importId === $import->id);
});

test('sync job executes and creates invoices in QuickBooks', function () {
    $user = User::factory()->create();
    [$team, $connection] = makeTeamWithQbo($user);

    $import = InvoiceImport::create([
        'team_id' => $team->id,
        'uploaded_by' => $user->id,
        'status' => InvoiceImportStatus::Completed,
        'storage_disk' => 'local',
        'storage_path' => 'imports/test.xlsx',
        'original_filename' => 'Invoice.xlsx',
        'file_hash' => str_repeat('c', 64),
        'file_size' => 20,
    ]);

    InvoiceRecord::create([
        'invoice_import_id' => $import->id,
        'source_row_number' => 2,
        'doc_number' => 'INV0906-01',
        'customer' => 'Banteay Meanchey Co., Ltd.',
        'txn_date' => '2026-09-06',
        'line_item' => 'Bicycle',
        'line_amount' => 2400.00,
        'payload' => [
            'Doc Number' => 'INV0906-01',
            'Customer' => 'Banteay Meanchey Co., Ltd.',
            'Txn Date' => '9/6/2026',
            'Due Date' => '9/30/2026',
            'Bill Addr Line 1' => '12 National Road 5',
            'Bill Addr City' => 'Serei Saophoan',
            'Bill Addr State' => 'Banteay Meanchey',
            'Bill Addr Postal Code' => '1000',
            'Bill Addr Country' => 'Cambodia',
            'Line Item' => 'Bicycle',
            'Line Desc' => 'Standard city bicycle',
            'Line Qty' => '20.00',
            'Line Unit Price' => '120.00',
            'Line Amount' => '2,400.00',
        ],
    ]);

    Http::fake(function (\Illuminate\Http\Client\Request $request) {
        $url = $request->url();
        if (str_contains($url, '/query')) {
            if (str_contains($url, 'Customer')) {
                return Http::response([
                    'QueryResponse' => [
                        'Customer' => [['Id' => 'cust-1', 'DisplayName' => 'Banteay Meanchey Co., Ltd.']],
                    ],
                ], 200);
            }
            if (str_contains($url, 'Item')) {
                return Http::response([
                    'QueryResponse' => [
                        'Item' => [['Id' => 'item-1', 'Name' => 'Bicycle']],
                    ],
                ], 200);
            }
        }
        if (str_contains($url, '/invoice')) {
            return Http::response([
                'Invoice' => [
                    'Id' => 'qbo-inv-999',
                    'DocNumber' => 'INV0906-01',
                ],
            ], 200);
        }
        return Http::response([], 200);
    });

    $job = new SyncImportToQuickBooks($import->id);
    $job->handle(new \App\Services\QuickBooks\QuickBooksClient());

    $import->refresh();
    expect($import->qbo_sync_status)->toBe('synced')
        ->and($import->qbo_synced_count)->toBe(1)
        ->and($import->qbo_failed_count)->toBe(0);

    $record = InvoiceRecord::where('doc_number', 'INV0906-01')->first();
    expect($record->qbo_invoice_id)->toBe('qbo-inv-999')
        ->and($record->qbo_synced_at)->not()->toBeNull();
});
