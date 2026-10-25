<?php

namespace App\Http\Controllers;

use App\Models\Retailer;
use App\Services\BankVerification\RetailerBankVerificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class RetailerBankVerificationController extends Controller
{
    public function __invoke(Request $request, RetailerBankVerificationService $service)
    {
        $data = $request->validate(['retry' => ['sometimes', 'boolean'], 'retailer_id' => ['prohibited'], 'verification_id' => ['prohibited'], 'iban' => ['prohibited']]);
        $retailer = $request->user()->retailer;
        abort_unless($retailer, 403);
        Gate::authorize('update', $retailer);
        DB::transaction(function () use ($retailer, $service, $data) {
            $locked = Retailer::lockForUpdate()->findOrFail($retailer->id);
            Gate::authorize('update', $locked);
            $service->requestLocked($locked, null, explicit: true, retry: $data['retry'] ?? false);
        });

        return to_route('retailer.profile')->with('status', 'Richiesta di verifica registrata. Consulta l’esito nella sezione Coordinate bancarie.');
    }
}
