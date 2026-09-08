<?php

namespace App\Http\Controllers\QuickBooks;

use App\Enums\TeamPermission;
use App\Http\Controllers\Controller;
use App\Models\QuickbooksConnection;
use App\Models\Team;
use App\Services\QuickBooks\QuickBooksClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

class QuickBooksAuthController extends Controller
{
    public function connect(Request $request, Team $current_team, QuickBooksClient $client): RedirectResponse
    {
        $this->ensureCanManage($request, $current_team);

        if (empty(config('quickbooks.client_id')) || empty(config('quickbooks.client_secret'))) {
            return to_route('imports.index', $current_team)->with('toast', [
                'type' => 'error',
                'message' => 'QuickBooks API credentials are not configured. Please set QUICKBOOKS_CLIENT_ID and QUICKBOOKS_CLIENT_SECRET in .env.',
            ]);
        }

        $state = Str::random(40);
        $request->session()->put('qbo_oauth_state', $state);
        $request->session()->put('qbo_team_id', $current_team->id);

        $redirectUri = config('quickbooks.redirect_uri') ?: route('quickbooks.callback', $current_team);
        $request->session()->put('qbo_redirect_uri', $redirectUri);

        $authUrl = $client->getAuthorizationUrl($state, $redirectUri);

        return redirect()->away($authUrl);
    }

    public function callback(Request $request, QuickBooksClient $client, ?Team $current_team = null): RedirectResponse
    {
        if (! $current_team) {
            $teamId = $request->session()->get('qbo_team_id');
            $current_team = $teamId ? Team::find($teamId) : ($request->user()?->currentTeam ?? $request->user()?->teams()->first());
        }

        abort_unless($current_team, 404, 'Team not found.');
        $this->ensureCanManage($request, $current_team);

        $savedState = $request->session()->pull('qbo_oauth_state');
        $state = $request->query('state');
        $code = $request->query('code');
        $realmId = $request->query('realmId');

        if (! $state || $state !== $savedState || ! $code || ! $realmId) {
            return to_route('imports.index', $current_team)->with('toast', [
                'type' => 'error',
                'message' => 'QuickBooks authorization failed or was cancelled.',
            ]);
        }

        try {
            $redirectUri = $request->session()->pull('qbo_redirect_uri') 
                ?: config('quickbooks.redirect_uri') 
                ?: route('quickbooks.callback', $current_team);

            $tokens = $client->exchangeCodeForTokens($code, $redirectUri);

            $connection = QuickbooksConnection::updateOrCreate(
                ['team_id' => $current_team->id],
                [
                    'realm_id' => $realmId,
                    'access_token' => $tokens['access_token'],
                    'refresh_token' => $tokens['refresh_token'],
                    'access_token_expires_at' => now()->addSeconds($tokens['expires_in'] ?? 3600),
                    'refresh_token_expires_at' => now()->addSeconds($tokens['x_refresh_token_expires_in'] ?? 8726400),
                    'environment' => config('quickbooks.environment', 'sandbox'),
                ]
            );

            // Attempt to grab company name
            try {
                $companyInfo = $client->getCompanyInfo($connection);
                if (! empty($companyInfo['CompanyName'])) {
                    $connection->update(['company_name' => $companyInfo['CompanyName']]);
                }
            } catch (Throwable) {
                // Ignore company info lookup errors
            }

            return to_route('imports.index', $current_team)->with('toast', [
                'type' => 'success',
                'message' => 'Successfully connected to QuickBooks Online!',
            ]);
        } catch (Throwable $e) {
            return to_route('imports.index', $current_team)->with('toast', [
                'type' => 'error',
                'message' => 'Failed to connect to QuickBooks: ' . $e->getMessage(),
            ]);
        }
    }

    public function disconnect(Request $request, Team $current_team): RedirectResponse
    {
        $this->ensureCanManage($request, $current_team);

        $current_team->quickbooksConnection()->delete();

        return to_route('imports.index', $current_team)->with('toast', [
            'type' => 'success',
            'message' => 'QuickBooks Online account disconnected.',
        ]);
    }

    private function ensureCanManage(Request $request, Team $team): void
    {
        abort_unless(
            $request->user()->hasTeamPermission($team, TeamPermission::ImportInvoices),
            403
        );
    }
}
