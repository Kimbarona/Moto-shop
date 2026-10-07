<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accounts created (or reset) by someone else must set their own password first.
 */
class ForcePasswordChange
{
    private const ALLOWED_ROUTES = ['security.edit', 'user-password.update', 'logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->must_change_password && ! $request->routeIs(self::ALLOWED_ROUTES)) {
            return redirect()->route('security.edit')
                ->with('warning', 'Please set a new password before continuing.');
        }

        return $next($request);
    }
}
