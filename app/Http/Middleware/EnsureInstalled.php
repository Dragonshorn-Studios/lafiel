<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureInstalled
{
    /**
     * Routes that must stay reachable in both install states: the Livewire
     * update endpoint serves the setup form itself, and the health check
     * must never depend on application state.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('livewire.update', 'up')) {
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
