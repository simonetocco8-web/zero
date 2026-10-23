<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\PasswordResetLinkRequest;
use App\Services\DeliveringMailerGuard;
use App\Support\PasswordStatusMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;
use Throwable;

class PasswordResetLinkController extends Controller
{
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    public function store(PasswordResetLinkRequest $request, DeliveringMailerGuard $guard): RedirectResponse
    {
        try {
            $guard->ensure();
            $status = Password::sendResetLink($request->validated());
        } catch (Throwable) {
            // Transport errors may contain private reset links or SMTP credentials.
            return back()->withInput($request->only('email'))->withErrors([
                'email' => 'Il servizio email non è momentaneamente disponibile. Riprova più tardi.',
            ]);
        }

        return $status === Password::RESET_LINK_SENT
            ? back()->with('status', PasswordStatusMessage::for($status))
            : back()->withInput($request->only('email'))->withErrors(['email' => PasswordStatusMessage::for($status)]);
    }
}
