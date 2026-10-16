<x-layouts.retailer title="Pagamenti marketplace">
    <h1>Pagamenti marketplace</h1>
    <p class="my-4">Collega e verifica la tua azienda con Stripe per ricevere gli incassi delle tue vendite e dei conguagli.</p>
    @if($errors->any())<x-alert variant="danger">{{ $errors->first() }}</x-alert>@endif
    <x-card>
        <p>{{ $account && $account->charges_enabled && $account->payouts_enabled && $account->transfers_active && $account->details_submitted ? 'Account verificato: controlli periodici presso Stripe.' : 'Verifica pagamenti da completare.' }}</p>
        <form class="mt-5" method="POST" action="{{ route('retailer.connect.begin') }}">@csrf<x-button type="submit">Continua su Stripe</x-button></form>
    </x-card>
    <p class="my-4">Il saldo rivenditore è liquidabile, ma non spendibile per acquisti al lancio. La disponibilità richiede consegna documentata o ritiro OTP, 72 ore, assenza di contestazioni e fondi disponibili presso Stripe. I bonifici dipendono dai tempi del provider e della banca.</p>
</x-layouts.retailer>
