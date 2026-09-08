<?php

namespace App\Services\QuickBooks;

use App\Models\QuickbooksConnection;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class QuickBooksClient
{
    public function getAuthorizationUrl(string $state, ?string $redirectUri = null): string
    {
        $clientId = config('quickbooks.client_id');
        $redirect = $redirectUri ?: config('quickbooks.redirect_uri');
        $scope = config('quickbooks.scope');
        $oauthUrl = config('quickbooks.oauth_url');

        $query = http_build_query([
            'client_id' => $clientId,
            'response_type' => 'code',
            'scope' => $scope,
            'redirect_uri' => $redirect,
            'state' => $state,
        ]);

        return "{$oauthUrl}?{$query}";
    }

    /**
     * @return array{access_token: string, refresh_token: string, expires_in: int, x_refresh_token_expires_in: int}
     */
    public function exchangeCodeForTokens(string $code, ?string $redirectUri = null): array
    {
        $clientId = config('quickbooks.client_id');
        $clientSecret = config('quickbooks.client_secret');
        $tokenUrl = config('quickbooks.token_url');
        $redirect = $redirectUri ?: config('quickbooks.redirect_uri');

        $response = Http::asForm()
            ->withBasicAuth($clientId, $clientSecret)
            ->acceptJson()
            ->post($tokenUrl, [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $redirect,
            ]);

        if ($response->failed()) {
            Log::error('QuickBooks token exchange failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new RuntimeException('Failed to exchange authorization code for QuickBooks tokens: ' . ($response->json('error_description') ?? $response->body()));
        }

        return $response->json();
    }

    public function refreshToken(QuickbooksConnection $connection): QuickbooksConnection
    {
        $clientId = config('quickbooks.client_id');
        $clientSecret = config('quickbooks.client_secret');
        $tokenUrl = config('quickbooks.token_url');

        $response = Http::asForm()
            ->withBasicAuth($clientId, $clientSecret)
            ->acceptJson()
            ->post($tokenUrl, [
                'grant_type' => 'refresh_token',
                'refresh_token' => $connection->refresh_token,
            ]);

        if ($response->failed()) {
            Log::error('QuickBooks token refresh failed', [
                'connection_id' => $connection->id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new RuntimeException('Failed to refresh QuickBooks token: ' . ($response->json('error_description') ?? $response->body()));
        }

        $data = $response->json();

        $connection->update([
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? $connection->refresh_token,
            'access_token_expires_at' => now()->addSeconds($data['expires_in'] ?? 3600),
            'refresh_token_expires_at' => now()->addSeconds($data['x_refresh_token_expires_in'] ?? 8726400),
        ]);

        return $connection->fresh();
    }

    public function ensureFreshTokens(QuickbooksConnection $connection): QuickbooksConnection
    {
        // Refresh token if expired or expiring within 5 minutes
        if ($connection->access_token_expires_at->subMinutes(5)->isPast()) {
            return $this->refreshToken($connection);
        }

        return $connection;
    }

    public function getCompanyInfo(QuickbooksConnection $connection): array
    {
        $connection = $this->ensureFreshTokens($connection);
        $baseUrl = $this->getBaseUrl($connection);
        $realmId = $connection->realm_id;

        $response = Http::withToken($connection->access_token)
            ->acceptJson()
            ->get("{$baseUrl}/v3/company/{$realmId}/companyinfo/{$realmId}");

        if ($response->failed()) {
            Log::warning('Failed to fetch QuickBooks company info', ['body' => $response->body()]);
            return [];
        }

        return $response->json('CompanyInfo') ?? [];
    }

    /** @var array<string, array<string, string>> realmId => [displayName => customerId] */
    private array $customerCache = [];

    /** @var array<string, array<string, string>> realmId => [itemName => itemId] */
    private array $itemCache = [];

    /**
     * Preload existing customers and items into memory to avoid per-row queries.
     */
    public function preloadEntities(QuickbooksConnection $connection): void
    {
        $connection = $this->ensureFreshTokens($connection);
        $baseUrl = $this->getBaseUrl($connection);
        $realmId = $connection->realm_id;

        if (! isset($this->customerCache[$realmId])) {
            $this->customerCache[$realmId] = [];
            try {
                $response = Http::withToken($connection->access_token)
                    ->acceptJson()
                    ->timeout(30)
                    ->get("{$baseUrl}/v3/company/{$realmId}/query", [
                        'query' => 'select Id, DisplayName from Customer maxresults 1000',
                    ]);
                foreach ($response->json('QueryResponse.Customer') ?? [] as $cust) {
                    if (isset($cust['DisplayName'], $cust['Id'])) {
                        $this->customerCache[$realmId][$cust['DisplayName']] = (string) $cust['Id'];
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('QuickBooks preload customers failed: ' . $e->getMessage());
            }
        }

        if (! isset($this->itemCache[$realmId])) {
            $this->itemCache[$realmId] = [];
            try {
                $response = Http::withToken($connection->access_token)
                    ->acceptJson()
                    ->timeout(30)
                    ->get("{$baseUrl}/v3/company/{$realmId}/query", [
                        'query' => 'select Id, Name from Item maxresults 1000',
                    ]);
                foreach ($response->json('QueryResponse.Item') ?? [] as $item) {
                    if (isset($item['Name'], $item['Id'])) {
                        $this->itemCache[$realmId][$item['Name']] = (string) $item['Id'];
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('QuickBooks preload items failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * Search for existing customer by display name, or create one (cached in-memory).
     */
    public function findOrCreateCustomer(QuickbooksConnection $connection, string $displayName, array $billingAddress = []): string
    {
        $realmId = $connection->realm_id;
        if (isset($this->customerCache[$realmId][$displayName])) {
            return $this->customerCache[$realmId][$displayName];
        }

        $connection = $this->ensureFreshTokens($connection);
        $baseUrl = $this->getBaseUrl($connection);

        // Escape single quotes for QuickBooks SQL query
        $escapedName = str_replace("'", "\\'", $displayName);
        $query = "select Id, DisplayName from Customer where DisplayName = '{$escapedName}'";

        $response = Http::withToken($connection->access_token)
            ->acceptJson()
            ->get("{$baseUrl}/v3/company/{$realmId}/query", [
                'query' => $query,
            ]);

        $customers = $response->json('QueryResponse.Customer') ?? [];
        if (!empty($customers)) {
            $id = (string) $customers[0]['Id'];
            $this->customerCache[$realmId][$displayName] = $id;
            return $id;
        }

        // Create customer if not found
        $payload = [
            'DisplayName' => $displayName,
        ];

        if (!empty($billingAddress)) {
            $filteredAddr = array_filter([
                'Line1' => $billingAddress['Line1'] ?? null,
                'Line2' => $billingAddress['Line2'] ?? null,
                'City' => $billingAddress['City'] ?? null,
                'CountrySubDivisionCode' => $billingAddress['CountrySubDivisionCode'] ?? null,
                'PostalCode' => $billingAddress['PostalCode'] ?? null,
                'Country' => $billingAddress['Country'] ?? null,
            ]);

            if (!empty($filteredAddr)) {
                $payload['BillAddr'] = $filteredAddr;
            }
        }

        $createResponse = Http::withToken($connection->access_token)
            ->acceptJson()
            ->asJson()
            ->post("{$baseUrl}/v3/company/{$realmId}/customer", $payload);

        if ($createResponse->failed()) {
            throw new RuntimeException("Failed to create customer '{$displayName}' in QuickBooks: " . $createResponse->body());
        }

        $id = (string) $createResponse->json('Customer.Id');
        $this->customerCache[$realmId][$displayName] = $id;
        return $id;
    }

    /**
     * Search for existing item by name, or create a service item (cached in-memory).
     */
    public function findOrCreateItem(QuickbooksConnection $connection, string $name, string $desc = '', float $unitPrice = 0.0): string
    {
        $realmId = $connection->realm_id;
        if (isset($this->itemCache[$realmId][$name])) {
            return $this->itemCache[$realmId][$name];
        }

        $connection = $this->ensureFreshTokens($connection);
        $baseUrl = $this->getBaseUrl($connection);

        $escapedName = str_replace("'", "\\'", $name);
        $query = "select Id, Name from Item where Name = '{$escapedName}'";

        $response = Http::withToken($connection->access_token)
            ->acceptJson()
            ->get("{$baseUrl}/v3/company/{$realmId}/query", [
                'query' => $query,
            ]);

        $items = $response->json('QueryResponse.Item') ?? [];
        if (!empty($items)) {
            $id = (string) $items[0]['Id'];
            $this->itemCache[$realmId][$name] = $id;
            return $id;
        }

        // To create an item, QBO requires an IncomeAccountRef
        $incomeAccountRef = $this->getIncomeAccountRef($connection);

        $payload = [
            'Name' => $name,
            'Type' => 'Service',
            'Description' => $desc,
            'UnitPrice' => $unitPrice,
            'IncomeAccountRef' => $incomeAccountRef,
        ];

        $createResponse = Http::withToken($connection->access_token)
            ->acceptJson()
            ->asJson()
            ->post("{$baseUrl}/v3/company/{$realmId}/item", $payload);

        if ($createResponse->failed()) {
            throw new RuntimeException("Failed to create item '{$name}' in QuickBooks: " . $createResponse->body());
        }

        $id = (string) $createResponse->json('Item.Id');
        $this->itemCache[$realmId][$name] = $id;
        return $id;
    }

    /**
     * Create an invoice in QuickBooks Online.
     */
    public function createInvoice(QuickbooksConnection $connection, array $invoicePayload): array
    {
        $connection = $this->ensureFreshTokens($connection);
        $baseUrl = $this->getBaseUrl($connection);
        $realmId = $connection->realm_id;

        $response = Http::withToken($connection->access_token)
            ->acceptJson()
            ->asJson()
            ->post("{$baseUrl}/v3/company/{$realmId}/invoice", $invoicePayload);

        if ($response->failed()) {
            Log::error('QuickBooks invoice creation failed', [
                'payload' => $invoicePayload,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            $errorMsg = $response->json('Fault.Error.0.Detail') 
                ?? $response->json('Fault.Error.0.Message') 
                ?? $response->body();

            throw new RuntimeException("QuickBooks API Error: {$errorMsg}");
        }

        return $response->json('Invoice') ?? [];
    }

    /**
     * Batch create up to 30 invoices in a single HTTP request using QuickBooks Batch API.
     *
     * @param array<int, array{bId: string, Invoice: array}> $batchItems
     * @return array<string, array{success: bool, invoiceId?: string, error?: string}>
     */
    public function batchCreateInvoices(QuickbooksConnection $connection, array $batchItems): array
    {
        if (empty($batchItems)) {
            return [];
        }

        $connection = $this->ensureFreshTokens($connection);
        $baseUrl = $this->getBaseUrl($connection);
        $realmId = $connection->realm_id;

        $requests = [];
        foreach ($batchItems as $item) {
            $requests[] = [
                'bId' => (string) $item['bId'],
                'operation' => 'create',
                'Invoice' => $item['Invoice'],
            ];
        }

        $response = Http::withToken($connection->access_token)
            ->acceptJson()
            ->asJson()
            ->timeout(120)
            ->post("{$baseUrl}/v3/company/{$realmId}/batch", [
                'BatchItemRequest' => $requests,
            ]);

        if ($response->failed()) {
            Log::error('QuickBooks batch invoice creation request failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            $errorMsg = $response->json('Fault.Error.0.Detail') 
                ?? $response->json('Fault.Error.0.Message') 
                ?? $response->body();

            throw new RuntimeException("QuickBooks Batch API Error: {$errorMsg}");
        }

        $results = [];
        $batchResponses = $response->json('BatchItemResponse') ?? [];

        foreach ($batchResponses as $itemResponse) {
            $bId = (string) ($itemResponse['bId'] ?? '');
            if (!$bId) continue;

            if (isset($itemResponse['Fault'])) {
                $err = $itemResponse['Fault']['Error'][0]['Detail']
                    ?? $itemResponse['Fault']['Error'][0]['Message']
                    ?? 'Unknown batch error';

                // If QuickBooks says this invoice already exists, extract the existing TxnId or find it
                $matchedTxnId = null;
                if (preg_match('/Duplicate Document Number/i', $err) && preg_match('/TxnId=(\d+)/i', $err, $m)) {
                    $matchedTxnId = (string) $m[1];
                }

                if ($matchedTxnId !== null) {
                    $results[$bId] = [
                        'success' => true,
                        'invoiceId' => $matchedTxnId,
                        'note' => 'Already existed in QuickBooks Online (linked)',
                    ];
                } else {
                    $results[$bId] = [
                        'success' => false,
                        'error' => $err,
                    ];
                }
            } elseif (isset($itemResponse['Invoice']['Id'])) {
                $results[$bId] = [
                    'success' => true,
                    'invoiceId' => (string) $itemResponse['Invoice']['Id'],
                ];
            } else {
                $results[$bId] = [
                    'success' => false,
                    'error' => 'No invoice ID returned in batch response.',
                ];
            }
        }

        // For any bId that didn't receive an explicit response
        foreach ($batchItems as $item) {
            $bId = (string) $item['bId'];
            if (!isset($results[$bId])) {
                $results[$bId] = [
                    'success' => false,
                    'error' => 'Missing from QuickBooks batch response.',
                ];
            }
        }

        return $results;
    }

    /**
     * Find existing invoice ID by DocNumber in QuickBooks Online.
     */
    public function findInvoiceByDocNumber(QuickbooksConnection $connection, string $docNumber): ?string
    {
        try {
            $escaped = addslashes($docNumber);
            $baseUrl = $this->getBaseUrl($connection);
            $realmId = $connection->realm_id;
            $connection = $this->ensureFreshTokens($connection);

            $response = Http::withToken($connection->access_token)
                ->acceptJson()
                ->get("{$baseUrl}/v3/company/{$realmId}/query", [
                    'query' => "select Id from Invoice where DocNumber = '{$escaped}' maxresults 1",
                ]);

            $invoices = $response->json('QueryResponse.Invoice') ?? [];
            if (! empty($invoices) && isset($invoices[0]['Id'])) {
                return (string) $invoices[0]['Id'];
            }
        } catch (\Throwable $e) {
            Log::warning("findInvoiceByDocNumber failed for {$docNumber}: " . $e->getMessage());
        }

        return null;
    }


    /** @var array<string, array<int, array{Id: string, Name: string}>> */
    private array $taxCodeCache = [];

    /**
     * Resolve tax code reference for QuickBooks Online non-US companies (GST, VAT, etc.).
     */
    public function resolveTaxCodeRef(QuickbooksConnection $connection, ?string $rawCode): ?array
    {
        $realmId = $connection->realm_id;
        if (! isset($this->taxCodeCache[$realmId])) {
            $connection = $this->ensureFreshTokens($connection);
            $baseUrl = $this->getBaseUrl($connection);
            $response = Http::withToken($connection->access_token)
                ->acceptJson()
                ->get("{$baseUrl}/v3/company/{$realmId}/query", [
                    'query' => 'select Id, Name, Description from TaxCode',
                ]);
            $this->taxCodeCache[$realmId] = $response->json('QueryResponse.TaxCode') ?? [];
        }

        $taxCodes = $this->taxCodeCache[$realmId];
        if (empty($taxCodes)) {
            return null;
        }

        $normalized = strtolower(trim((string) $rawCode));

        // 1. Direct exact name match
        foreach ($taxCodes as $tc) {
            if (strtolower($tc['Name']) === $normalized) {
                return ['value' => (string) $tc['Id'], 'name' => $tc['Name']];
            }
        }

        // 2. Mapping for zero/non-tax
        if (str_contains($normalized, 'zero') || str_contains($normalized, 'non') || str_contains($normalized, 'free') || str_contains($normalized, '0')) {
            foreach ($taxCodes as $tc) {
                $name = strtolower($tc['Name']);
                if ($name === 'gst free' || $name === 'gst-free' || $name === 'zero' || str_contains($name, 'free')) {
                    return ['value' => (string) $tc['Id'], 'name' => $tc['Name']];
                }
            }
        }

        // 3. Mapping for standard tax/VAT
        if (str_contains($normalized, 'vat') || str_contains($normalized, 'output') || str_contains($normalized, 'gst') || str_contains($normalized, 'tax') || str_contains($normalized, '10')) {
            foreach ($taxCodes as $tc) {
                $name = strtolower($tc['Name']);
                if ($name === 'gst' || $name === 'sale 10%' || str_contains($name, 'standard')) {
                    return ['value' => (string) $tc['Id'], 'name' => $tc['Name']];
                }
            }
        }

        // 4. Default fallback: GST free or first active tax code
        foreach ($taxCodes as $tc) {
            $name = strtolower($tc['Name']);
            if ($name === 'gst free' || $name === 'gst') {
                return ['value' => (string) $tc['Id'], 'name' => $tc['Name']];
            }
        }

        return ['value' => (string) $taxCodes[0]['Id'], 'name' => $taxCodes[0]['Name']];
    }

    /**
     * Query default income account for item creation.
     */
    private function getIncomeAccountRef(QuickbooksConnection $connection): array
    {
        $baseUrl = $this->getBaseUrl($connection);
        $realmId = $connection->realm_id;

        $query = "select Id, Name from Account where AccountType = 'Income' maxresults 1";
        $response = Http::withToken($connection->access_token)
            ->acceptJson()
            ->get("{$baseUrl}/v3/company/{$realmId}/query", ['query' => $query]);

        $accounts = $response->json('QueryResponse.Account') ?? [];
        if (!empty($accounts)) {
            return [
                'value' => (string) $accounts[0]['Id'],
                'name' => (string) $accounts[0]['Name'],
            ];
        }

        // Fallback default
        return ['value' => '1'];
    }

    private function getBaseUrl(QuickbooksConnection $connection): string
    {
        return $connection->environment === 'production'
            ? 'https://quickbooks.api.intuit.com'
            : 'https://sandbox-quickbooks.api.intuit.com';
    }
}
