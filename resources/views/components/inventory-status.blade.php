@props(['item'])
<x-badge :variant="match($item->status->value){'published'=>'success','rejected'=>'danger','pending','change_pending'=>'warning',default=>'neutral'}">{{ match($item->status->value){'draft'=>'Bozza','pending'=>'In verifica','published'=>'Pubblicato','change_pending'=>'Modifica in verifica','rejected'=>'Rifiutato',default=>'Archiviato'} }}</x-badge>
