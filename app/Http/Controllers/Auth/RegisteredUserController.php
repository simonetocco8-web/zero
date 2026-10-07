<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\RegisterUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\Plan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    public function create(): View
    {
        return view('auth.register', ['plans' => Plan::whereIn('code', ['free', 'pro'])->where('is_active', true)->get()]);
    }

    public function store(RegisterRequest $request, RegisterUser $register): RedirectResponse
    {
        Auth::login($register->handle($request->validated()));
        $request->session()->regenerate();

        return redirect(route('dashboard', absolute: false));
    }
}
