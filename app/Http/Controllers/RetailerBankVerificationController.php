<?php

namespace App\Http\Controllers;

use App\Http\Requests\RetailerBankVerificationRequest;
use App\Models\Retailer;
use App\Services\BankVerification\RetailerBankVerificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class RetailerBankVerificationController extends Controller
{
    public function __invoke(RetailerBankVerificationRequest $request, RetailerBankVerificationService $service)
    {
        $data = $request->validated();
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
