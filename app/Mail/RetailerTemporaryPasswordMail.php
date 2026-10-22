<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class RetailerTemporaryPasswordMail extends Mailable
{
    public function __construct(public string $accountEmail, protected string $temporaryPassword) {}

    public function build(): static
    {
        return $this->subject('ZeroMagazzino — nuova password temporanea')
            ->view('mail.retailer-temporary-password', [
                'accountEmail' => $this->accountEmail,
                'temporaryPassword' => $this->temporaryPassword,
                'loginUrl' => route('login'),
            ]);
    }
}
