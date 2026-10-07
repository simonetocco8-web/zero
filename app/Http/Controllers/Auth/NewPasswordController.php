<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\ResetUserPassword;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

class NewPasswordController extends Controller
{
    public function create(Request $request): View
    {
        return view('auth.reset-password', ['request' => $request]);
    }

    public function store(ResetPasswordRequest $request, ResetUserPassword $reset): RedirectResponse
    {
        $status = Password::reset(
            $request->validated(),
            fn (User $user, string $password) => $reset->handle($user, $password)
        );

        return $status === Password::PASSWORD_RESET
            ? to_route('login')->with('status', __($status))
            : back()->withInput($request->only('email'))->withErrors(['email' => __($status)]);
    }
}
