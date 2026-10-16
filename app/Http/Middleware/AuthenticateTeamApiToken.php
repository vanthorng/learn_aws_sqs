<?php

namespace App\Http\Middleware;

use App\Models\Team;
use App\Models\TeamApiToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateTeamApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $plainToken = $request->bearerToken();
        $team = $request->route('team');

        if (! $plainToken || ! $team instanceof Team) {
            abort(401, 'A valid API token is required.');
        }

        $token = TeamApiToken::query()
            ->where('token_hash', hash('sha256', $plainToken))
            ->first();

        if (! $token || ! $token->isActive() || $token->team_id !== $team->id) {
            abort(401, 'A valid API token is required.');
        }

        $token->forceFill(['last_used_at' => now()])->save();
        $request->attributes->set('teamApiToken', $token);

        return $next($request);
    }
}
