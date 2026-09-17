<?php

namespace App\Http\Controllers;

use App\Enums\TeamPermission;
use App\Models\Team;
use App\Models\TeamApiToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TeamApiTokenController extends Controller
{
    public function store(Request $request, Team $team): RedirectResponse
    {
        abort_unless($request->user()->hasTeamPermission($team, TeamPermission::ImportInvoices), 403);
        $validated = $request->validate(['name' => ['required', 'string', 'max:100']]);
        $plainToken = 'imp_'.Str::random(48);

        TeamApiToken::create([
            'team_id' => $team->id,
            'created_by' => $request->user()->id,
            'name' => $validated['name'],
            'token_hash' => hash('sha256', $plainToken),
        ]);

        return back()->with('newApiToken', $plainToken)->with('toast', [
            'type' => 'success',
            'message' => 'API key created. Copy it now; it will not be shown again.',
        ]);
    }

    public function destroy(Request $request, Team $team, TeamApiToken $apiToken): RedirectResponse
    {
        abort_unless($request->user()->hasTeamPermission($team, TeamPermission::ImportInvoices), 403);
        abort_unless($apiToken->team_id === $team->id, 404);

        $apiToken->update(['revoked_at' => now()]);

        return back()->with('toast', ['type' => 'success', 'message' => 'API key revoked.']);
    }
}
