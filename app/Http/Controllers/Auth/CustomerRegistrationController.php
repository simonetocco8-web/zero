<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\RegisterCustomer;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\CustomerRegisterRequest;
use Illuminate\Support\Facades\Auth;

class CustomerRegistrationController extends Controller
{
    public function create()
    {
        return view('auth.customer-register');
    }

    public function store(CustomerRegisterRequest $request, RegisterCustomer $action)
    {
        Auth::login($action->handle($request->validated()));
        $request->session()->regenerate();

        return to_route('customer.dashboard');
    }
}
