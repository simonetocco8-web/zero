@props(['exchange'])
<ol class="mt-5 border-l-2 border-line pl-5">
    @foreach($exchange->events as $event)
        @php
            $author = match($event->user_id) { null => 'Sistema', $exchange->retailerA->user_id => $exchange->retailerA->company_name, $exchange->retailerB->user_id => $exchange->retailerB->company_name, default => 'Utente #'.$event->user_id };
            $number = $exchange->versions->firstWhere('id', $event->exchange_version_id)?->number;
        @endphp
        <li class="relative min-w-0 pb-6 pl-3 last:pb-0">
            <span class="absolute -left-7 top-1.5 size-3 rounded-full border-2 border-surface bg-brand-600" aria-hidden="true"></span>
            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                <time datetime="{{ $event->created_at->toIso8601String() }}" class="text-sm font-semibold">{{ $event->created_at->format('H:i') }}<span class="ml-2 text-xs font-normal text-muted">{{ $event->created_at->format('d/m/Y') }}</span></time>
                <p class="font-semibold">{{ match($event->action) { 'proposed' => 'Proposta inviata', 'countered' => 'Nuova controproposta', 'accepted_by' => 'Accettazione registrata', 'accepted' => 'Doppia accettazione', 'awaiting_difference_payment' => 'Conguaglio in attesa', 'confirmed' => 'Scambio confermato', 'rejected' => 'Scambio rifiutato', 'cancelled' => 'Scambio annullato', 'expired' => 'Scambio scaduto', 'difference_paid' => 'Conguaglio simulato riuscito', 'difference_failed' => 'Conguaglio simulato fallito', 'difference_cancelled' => 'Conguaglio annullato', 'received' => 'Ricezione merci confermata', 'completed' => 'Scambio completato', 'eligibility_lost' => 'Requisiti PRO non più validi', default => 'Evento registrato' } }}</p>
            </div>
            <p class="mt-1 break-words text-sm text-muted">{{ $author }}@if($number !== null) · Versione {{ $number }}@endif</p>
        </li>
    @endforeach
</ol>
