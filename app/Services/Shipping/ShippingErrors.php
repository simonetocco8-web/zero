<?php

namespace App\Services\Shipping;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ShippingErrors
{
    /** Message, retryable, HTTP status. No provider text is public. */
    public static function catalog(): array
    {
        return [
            'SHIPPING_UNEXPECTED' => ['Non siamo riusciti a calcolare la spedizione. Riprova; se il problema persiste, contatta l’assistenza.', true, 500],
            'SHIPPING_NETWORK' => ['Non è stato possibile comunicare con il servizio di calcolo delle spedizioni. Riprova. Se il problema persiste, contatta l’assistenza.', true, 503],
            'SHIPPING_TIMEOUT' => ['Il calcolo della spedizione sta impiegando troppo tempo. Riprova tra qualche istante.', true, 504],
            'SHIPPING_INVALID_POSTAL_CODE' => ['Il formato del CAP inserito non è valido. Verifica il CAP e riprova.', false, 422],
            'SHIPPING_INCOMPLETE_ADDRESS' => ['Per calcolare la spedizione è necessario completare l’indirizzo di consegna. Controlla i campi indicati.', false, 422],
            'SHIPPING_INVALID_DATA' => ['I dati della spedizione non sono validi. Verifica indirizzi, quantità e imballaggi prima di riprovare.', false, 422],
            'SHIPPING_MISSING_WEIGHT' => ['Uno o più articoli non hanno un peso configurato. Contatta l’assistenza per calcolare la spedizione.', false, 422],
            'SHIPPING_MISSING_DIMENSIONS' => ['Mancano le dimensioni necessarie per calcolare questa spedizione. Contatta l’assistenza.', false, 422],
            'SHIPPING_PACKAGING' => ['La quantità non è compatibile con i colli dichiarati oppure i dati di imballaggio sono incompleti. Verifica la quantità o contatta il rivenditore.', false, 422],
            'SHIPPING_NO_RATES' => ['Al momento non sono disponibili tariffe per questa spedizione. Verifica i dati o prova un altro indirizzo.', false, 422],
            'SHIPPING_CONFIGURATION' => ['Il servizio di spedizione è temporaneamente non disponibile per un problema di configurazione. Contatta l’assistenza.', false, 503],
            'SHIPPING_RATE_LIMITED' => ['Sono state richieste troppe tariffe. Attendi prima di riprovare.', true, 429],
            'SHIPPING_UNAVAILABLE' => ['Il servizio di calcolo delle spedizioni è temporaneamente non disponibile. Riprova più tardi.', true, 503],
            'SHIPPING_INVALID_RESPONSE' => ['Il servizio di spedizione ha restituito una risposta non utilizzabile. Riprova più tardi.', true, 502],
            'SHIPPING_IN_PROGRESS' => ['Un calcolo per questi dati è già in corso. Attendi qualche istante e riprova.', true, 409],
            'SHIPPING_SESSION_EXPIRED' => ['La sessione è scaduta. Ricarica la pagina e accedi nuovamente prima di calcolare la spedizione.', false, 419],
            'SHIPPING_FORBIDDEN' => ['Non puoi calcolare questa spedizione con l’account attuale. Verifica l’accesso o contatta l’assistenza.', false, 403],
        ];
    }

    public static function handles(Request $request): bool
    {
        return $request->routeIs('shipping.quote', 'admin.shipping.quote', 'retailer.exchanges.shipping', 'retailer.exchanges.shipping.counter');
    }

    public static function response(Throwable $exception)
    {
        $code = 'SHIPPING_UNEXPECTED';
        $fields = [];
        $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : null;
        if ($exception instanceof ShippingFailure) {
            $code = $exception->shippingCode;
            $fields = $exception->fields;
        } elseif ($exception instanceof AuthenticationException || $exception instanceof TokenMismatchException || in_array($status, [401, 419], true)) {
            $code = 'SHIPPING_SESSION_EXPIRED';
        } elseif ($exception instanceof AuthorizationException || $status === 403) {
            $code = 'SHIPPING_FORBIDDEN';
        } elseif ($status === 429) {
            $code = 'SHIPPING_RATE_LIMITED';
        } elseif ($exception instanceof ValidationException) {
            $code = 'SHIPPING_INVALID_DATA';
            $fields = array_keys($exception->errors());
        } elseif ($status === 404 || $status === 422) {
            $code = 'SHIPPING_INVALID_DATA';
        }
        [$message, $retryable, $httpStatus] = self::catalog()[$code];
        $reference = 'SHP-'.Str::upper((string) Str::uuid());
        // Never log exception messages, request bodies, raw responses, URLs or headers.
        Log::warning('Shipping calculation failed', ['reference' => $reference, 'code' => $code, 'exception_class' => $exception::class] + ($exception instanceof ShippingFailure ? $exception->diagnostics : []));

        return response()->json(['success' => false, 'message' => $message, 'error' => compact('code', 'message', 'fields', 'retryable', 'reference'), 'errors' => array_fill_keys($fields ?: ['shipping'], [$message])], $httpStatus)->header('Cache-Control', 'private, no-store');
    }
}
