<?php

namespace App\Http\Controllers;

use App\Actions\Setup\CreateAdministrator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class SetupController extends Controller
{
    /**
     * Show the first-run setup screen.
     */
    public function show(): View
    {
        return view('pages.auth.setup');
    }

    /**
     * Create the single local administrator and sign in.
     */
    public function store(Request $request, CreateAdministrator $createAdministrator): RedirectResponse
    {
        $user = $createAdministrator->create($request->only(['name', 'email', 'password', 'password_confirmation']));

        if ($user === null) {
            return redirect()->route('login');
        }

        Auth::login($user);

        // From here on, scheduler and queue liveness checks are armed:
        // a missing heartbeat is a dead pipeline, not a fresh install.
        Cache::forever('ops:installed-at', now()->toIso8601String());

        return redirect()->route('overview');
    }
}
