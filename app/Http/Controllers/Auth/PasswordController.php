<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\UpdateUserPassword;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\UpdatePasswordRequest;
use Illuminate\Http\RedirectResponse;

class PasswordController extends Controller
{
    public function update(UpdatePasswordRequest $request, UpdateUserPassword $update): RedirectResponse
    {
        $update->handle($request->user(), $request->validated('password'));

        return back()->with('status', 'password-updated');
    }
}
