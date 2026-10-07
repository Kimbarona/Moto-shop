<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PasswordUpdateRequest;
use App\Support\HomeRedirect;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class SecurityController extends Controller
{
    /**
     * Show the password page (also the forced first-login password change).
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/security', [
            'mustChangePassword' => (bool) $request->user()->must_change_password,
        ]);
    }

    public function update(PasswordUpdateRequest $request): HttpResponse
    {
        $user = $request->user();
        $wasForced = $user->must_change_password;

        $user->forceFill([
            'password' => $request->password,
            'must_change_password' => false,
        ])->save();

        activity('User')->causedBy($user)->performedOn($user)->event('password_changed')->log('Password changed');

        if ($wasForced) {
            $request->session()->flash('success', 'Password updated. Welcome!');

            return HomeRedirect::home($request, $user);
        }

        return back()->with('success', 'Password updated.');
    }
}
