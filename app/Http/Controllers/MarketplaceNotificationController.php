<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class MarketplaceNotificationController extends Controller
{
    public function index(Request $r)
    {
        $f = $r->validate(['unread' => ['nullable', 'in:1']]);
        $q = $r->user()->notifications();
        if (! empty($f['unread'])) {
            $q->whereNull('read_at');
        }

        return view('notifications.index', ['notices' => $q->latest()->paginate(20)->withQueryString(), 'filters' => $f, 'user' => $r->user()]);
    }

    public function read(Request $r, string $notification)
    {
        $r->user()->notifications()->whereKey($notification)->firstOrFail()->markAsRead();

        return to_route('notifications.index')->with('status', 'Notifica letta.');
    }

    public function preferences(Request $r)
    {
        $data = $r->validate(['email_notifications' => ['required', 'boolean']]);
        $r->user()->update(['marketplace_email_notifications' => $data['email_notifications']]);

        return to_route('notifications.index')->with('status', 'Preferenze aggiornate.');
    }
}
