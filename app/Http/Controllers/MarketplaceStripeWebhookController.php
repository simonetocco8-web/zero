<?php

namespace App\Http\Controllers;

use App\Services\Payments\ReceiveMarketplaceStripeEvent;
use Illuminate\Http\Request;
use Stripe\Exception\SignatureVerificationException;

class MarketplaceStripeWebhookController extends Controller
{
    public function __invoke(Request $request, ReceiveMarketplaceStripeEvent $receiver)
    {
        try {
            $outcome = $receiver->handle($request->getContent(), (string) $request->header('Stripe-Signature'));
        } catch (SignatureVerificationException|\InvalidArgumentException $e) {
            return response()->json(['error' => 'Invalid marketplace event.'], 400);
        }

        return response()->json(['outcome' => $outcome]);
    }
}
