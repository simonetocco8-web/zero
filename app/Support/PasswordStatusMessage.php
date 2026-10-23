<?php

namespace App\Support;

use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Password;

class PasswordStatusMessage
{
    public static function for(string $status): string
    {
        $fallback = match ($status) {
            Password::RESET_LINK_SENT => 'Ti abbiamo inviato via email il link per reimpostare la password.',
            Password::PASSWORD_RESET => 'La password è stata aggiornata.',
            Password::INVALID_USER => 'Non troviamo un account con questo indirizzo email.',
            Password::RESET_THROTTLED => 'Troppi tentativi. Attendi prima di richiedere un nuovo link.',
            Password::INVALID_TOKEN => 'Il link di recupero non è valido o è scaduto.',
            default => 'Non è possibile completare la richiesta. Riprova più tardi.',
        };
        $message = Lang::get($status, [], 'it', false);

        return is_string($message) && trim($message) !== '' && $message !== $status && ! str_starts_with($message, 'passwords.')
            ? $message : $fallback;
    }
}
