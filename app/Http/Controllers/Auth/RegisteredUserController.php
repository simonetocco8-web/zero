<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\RegisterUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\CompanyLookupRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\Plan;
use App\Models\Retailer;
use App\Services\Company\CompanyRegistrationLookup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    public function create(Request $request): View
    {
        $request->session()->forget(CompanyRegistrationLookup::SESSION_KEY);

        return view('auth.register');
    }

    public function lookup(CompanyLookupRequest $request, CompanyRegistrationLookup $lookup): RedirectResponse
    {
        $vat = $request->validated('vat_number');
        $result = $lookup->lookup($vat);
        $request->session()->put(CompanyRegistrationLookup::SESSION_KEY, $result + ['vat_number' => $vat, 'source' => $result['status'] === 'found' ? 'openapi_company_start' : 'manual', 'looked_up_at' => now()->timestamp, 'ready' => $result['status'] !== 'unavailable']);

        return to_route('register.details');
    }

    public function details(Request $request): View|RedirectResponse
    {
        $context = $request->session()->get(CompanyRegistrationLookup::SESSION_KEY);
        if (! CompanyRegistrationLookup::validContext($context)) {
            $request->session()->forget(CompanyRegistrationLookup::SESSION_KEY);

            return to_route('register')->withErrors(['registration_step' => 'Inserisci nuovamente la Partita IVA per iniziare la registrazione.']);
        }

        return view('auth.register-details', ['context' => $context, 'company' => new Retailer($context['prefill'] + ['vat_number' => $context['vat_number']]), 'plans' => Plan::whereIn('code', ['free', 'pro'])->where('is_active', true)->get()]);
    }

    public function manual(Request $request): RedirectResponse
    {
        $context = $request->session()->get(CompanyRegistrationLookup::SESSION_KEY);
        if (! CompanyRegistrationLookup::validContext($context)) {
            return to_route('register');
        }
        $context['ready'] = true;
        $context['source'] = 'manual';
        $request->session()->put(CompanyRegistrationLookup::SESSION_KEY, $context);

        return to_route('register.details');
    }

    public function store(RegisterRequest $request, RegisterUser $register): RedirectResponse
    {
        Auth::login($register->handle($request->validated()));
        $request->session()->forget(CompanyRegistrationLookup::SESSION_KEY);
        $request->session()->regenerate();

        return redirect(route('dashboard', absolute: false));
    }
}
