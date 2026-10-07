<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Send a user to their role's home. The Filament back-office (/admin) is not an Inertia
 * page, so Inertia (XHR) requests get a 409 "location" response that makes the browser
 * do a full page load instead of trying to render Filament's HTML as an Inertia page.
 */
class HomeRedirect
{
    public static function to(Request $request, string $url, bool $intended = false): Response
    {
        $target = $url;

        if ($intended) {
            $wanted = $request->session()->pull('url.intended');

            // "/" just bounces to the role home again, so go there directly.
            if (is_string($wanted) && trim((string) parse_url($wanted, PHP_URL_PATH), '/') !== '') {
                $target = $wanted;
            }
        }

        return $request->header('X-Inertia') ? Inertia::location($target) : redirect($target);
    }

    public static function home(Request $request, User $user, bool $intended = false): Response
    {
        return static::to($request, $user->role->homePath(), $intended);
    }
}
