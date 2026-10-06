<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

class EnsureUserHasRole
{
    /**
     * Usage: ->middleware('role:admin,operator'). Allows the listed roles
     * only. A monitor-only account that wanders to another page is sent back
     * to its screen instead of seeing an error; everyone else gets a 403.
     */
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        $user = $request->user();

        if ($user && in_array($user->role?->value, $roles, true)) {
            return $next($request);
        }

        if ($user?->role === UserRole::Monitor && Route::has('monitor') && ! $request->routeIs('monitor')) {
            return redirect()->route('monitor');
        }

        abort(403, __('You do not have access to this page.'));
    }
}
