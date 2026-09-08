use App\Models\QuickbooksConnection;
use App\Models\Team;
use App\Services\QuickBooks\QuickBooksClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    config([
        'quickbooks.client_id' => 'test-client-id',
        'quickbooks.client_secret' => 'test-client-secret',
        'quickbooks.redirect_uri' => 'https://example.com/callback',
        'quickbooks.environment' => 'sandbox',
        'quickbooks.oauth_url' => 'https://appcenter.intuit.com/connect/oauth2',
        'quickbooks.token_url' => 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer',
    ]);
});

test('it generates correct authorization url', function () {
    $client = new QuickBooksClient();
    $url = $client->getAuthorizationUrl('random-state-123');

    expect($url)
        ->toContain('client_id=test-client-id')
        ->toContain('response_type=code')
        ->toContain('state=random-state-123')
        ->toContain('scope=com.intuit.quickbooks.accounting');
});

test('it exchanges authorization code for tokens', function () {
    Http::fake([
        'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer' => Http::response([
            'access_token' => 'mock-access-token',
            'refresh_token' => 'mock-refresh-token',
            'expires_in' => 3600,
            'x_refresh_token_expires_in' => 8726400,
        ], 200),
    ]);

    $client = new QuickBooksClient();
    $tokens = $client->exchangeCodeForTokens('sample-auth-code');

    expect($tokens['access_token'])->toBe('mock-access-token')
        ->and($tokens['refresh_token'])->toBe('mock-refresh-token');
});

test('it refreshes expired access token automatically', function () {
    Http::fake([
        'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer' => Http::response([
            'access_token' => 'new-access-token',
            'refresh_token' => 'new-refresh-token',
            'expires_in' => 3600,
            'x_refresh_token_expires_in' => 8726400,
        ], 200),
    ]);

    $team = Team::factory()->create();
    $connection = QuickbooksConnection::create([
        'team_id' => $team->id,
        'realm_id' => '123456789',
        'access_token' => 'old-token',
        'refresh_token' => 'old-refresh',
        'access_token_expires_at' => now()->subHour(), // Expired
        'refresh_token_expires_at' => now()->addDays(30),
        'environment' => 'sandbox',
    ]);

    $client = new QuickBooksClient();
    $refreshed = $client->ensureFreshTokens($connection);

    expect($refreshed->access_token)->toBe('new-access-token')
        ->and($refreshed->refresh_token)->toBe('new-refresh-token');
});

test('it finds existing customer or creates new one', function () {
    $team = Team::factory()->create();
    $connection = QuickbooksConnection::create([
        'team_id' => $team->id,
        'realm_id' => '987654321',
        'access_token' => 'valid-token',
        'refresh_token' => 'valid-refresh',
        'access_token_expires_at' => now()->addHour(),
        'refresh_token_expires_at' => now()->addDays(30),
        'environment' => 'sandbox',
    ]);

    Http::fake([
        'https://sandbox-quickbooks.api.intuit.com/v3/company/987654321/query*' => Http::response([
            'QueryResponse' => [
                'Customer' => [
                    ['Id' => 'cust-42', 'DisplayName' => 'Banteay Meanchey Co., Ltd.'],
                ],
            ],
        ], 200),
    ]);

    $client = new QuickBooksClient();
    $customerId = $client->findOrCreateCustomer($connection, 'Banteay Meanchey Co., Ltd.');

    expect($customerId)->toBe('cust-42');
});

test('it creates invoice via QuickBooks API', function () {
    $team = Team::factory()->create();
    $connection = QuickbooksConnection::create([
        'team_id' => $team->id,
        'realm_id' => '987654321',
        'access_token' => 'valid-token',
        'refresh_token' => 'valid-refresh',
        'access_token_expires_at' => now()->addHour(),
        'refresh_token_expires_at' => now()->addDays(30),
        'environment' => 'sandbox',
    ]);

    Http::fake([
        'https://sandbox-quickbooks.api.intuit.com/v3/company/987654321/invoice' => Http::response([
            'Invoice' => [
                'Id' => 'inv-101',
                'DocNumber' => 'INV0906-01',
            ],
        ], 200),
    ]);

    $client = new QuickBooksClient();
    $created = $client->createInvoice($connection, [
        'DocNumber' => 'INV0906-01',
        'Line' => [],
    ]);

    expect($created['Id'])->toBe('inv-101')
        ->and($created['DocNumber'])->toBe('INV0906-01');
});
