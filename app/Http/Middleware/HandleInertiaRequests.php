<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user,
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'currentTeam' => fn () => $user?->currentTeam ? $user->toUserTeam($user->currentTeam) : null,
            'teams' => fn () => $user?->toUserTeams(includeCurrent: true) ?? [],
            'notifications' => fn () => $user ? [
                'unreadCount' => $user->unreadNotifications()->count(),
                'items' => $user->notifications()->latest()->limit(8)->get()->map(fn ($notification) => [
                    'id' => $notification->id,
                    'title' => $notification->data['title'],
                    'message' => $notification->data['message'],
                    'url' => $notification->data['url'],
                    'readAt' => $notification->read_at?->toIso8601String(),
                    'createdAt' => $notification->created_at->toIso8601String(),
                ]),
            ] : ['unreadCount' => 0, 'items' => []],
            'flash' => [
                'newApiToken' => fn () => $request->session()->get('newApiToken'),
            ],
        ];
    }
}
