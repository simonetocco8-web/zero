<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        return to_route($request->user()->role === UserRole::Admin ? 'admin.dashboard' : 'retailer.dashboard');
    }
}
