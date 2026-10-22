@props(['item'])
@php
    [$icon, $label, $color] = match($item->status->value) {
        'draft' => ['document', 'Bozza', 'bg-raised text-muted'],
        'pending' => ['clock', 'In verifica', 'bg-amber-950 text-amber-200'],
        'published' => ['check-circle', 'Pubblicato', 'bg-emerald-950 text-emerald-200'],
        'change_pending' => ['refresh', 'Modifica in verifica', 'bg-amber-950 text-amber-200'],
        'rejected' => ['x-circle', 'Rifiutato', 'bg-red-950 text-red-200'],
        default => ['archive', 'Archiviato', 'bg-raised text-muted'],
    };
@endphp
<span role="img" aria-label="{{ $label }}" title="{{ $label }}" class="inline-flex size-9 items-center justify-center rounded-full {{ $color }}"><x-icon :name="$icon" /></span>
