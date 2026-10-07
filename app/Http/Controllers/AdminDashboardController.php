<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\InventoryItem;
use App\Models\PayoutRequest;
use App\Models\Retailer;
use App\Models\StorePublication;

class AdminDashboardController extends Controller
{
    public function __invoke()
    {
        return view('admin.dashboard', ['counts' => ['Rivenditori in verifica' => Retailer::where('status', 'pending')->count(), 'Giacenze in verifica' => InventoryItem::whereIn('status', ['pending', 'change_pending'])->count(), 'Bonifici in attesa' => PayoutRequest::where('status', 'pending')->count(), 'Pubblicazioni da recuperare' => StorePublication::whereIn('status', ['pending', 'failed'])->count()], 'logs' => AuditLog::with('actor')->latest()->limit(10)->get()]);
    }
}
