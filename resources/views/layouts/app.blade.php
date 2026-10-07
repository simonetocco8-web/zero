<x-area-shell :area="auth()->user()->role === \App\Enums\UserRole::Admin ? 'admin' : 'retailer'" title="ZeroMagazzino">{{ $slot }}</x-area-shell>
