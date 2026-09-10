<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureInstalled
{
    /**
     * Requests that must stay reachable in both install states: the
     * Livewire update endpoint (page components POST there and must not
     * be redirected as HTML), and the health check, which must never
     * depend on application state.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('*livewire.update') || $request->is('up')) {
            return $next($request);
        }

        $installed = User::query()->exists();

        if (! $installed && ! $request->routeIs('setup*')) {
            return redirect()->route('setup');
        }

        if ($installed && $request->routeIs('setup*')) {
            return redirect()->route('login');
        }

        return $next($request);
    }
}
