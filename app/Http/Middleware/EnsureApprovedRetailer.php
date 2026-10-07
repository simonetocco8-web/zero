<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class EnsureApprovedRetailer
{
    public function handle(Request $request, Closure $next): mixed
    {
        $retailer = $request->user()->retailer;
        abort_unless($retailer, 403, 'Profilo rivenditore mancante.');
        Gate::authorize('operate', $retailer);

        return $next($request);
    }
}
