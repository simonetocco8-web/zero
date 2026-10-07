@props(['retailer'])
@php($label = match($retailer->status->value) { 'pending'=>'Profilo in verifica', 'approved'=>'Profilo approvato', 'rejected'=>'Iscrizione non approvata', default=>'Profilo sospeso' })
<x-alert :variant="$retailer->status->value === 'approved' ? 'success' : 'warning'" :title="$label">
@if($retailer->status->value === 'pending') Stiamo verificando la tua azienda. Puoi aggiornare il profilo; le funzioni operative saranno disponibili dopo l’approvazione.
@elseif($retailer->status->value === 'rejected') {{ $retailer->rejection_reason ?? 'Contatta il supporto per ulteriori informazioni.' }}
@elseif($retailer->status->value === 'suspended') Contatta il supporto per informazioni sulla sospensione.
@else Le funzioni operative sono abilitate.
@endif
</x-alert>
