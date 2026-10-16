<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class MarketplaceNoticeMail extends Mailable
{
    public function __construct(public array $notice) {}

    public function build(): static
    {
        return $this->subject($this->notice['title'])->view('mail.marketplace-notice', ['notice' => $this->notice]);
    }
}
