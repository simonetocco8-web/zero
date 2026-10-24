@props(['vendor'])
@php
    $steps = ['paid' => 'Pagato', 'preparing' => 'Preparazione', $vendor->delivery_mode === 'shipping' ? 'shipped' : 'ready_for_pickup' => $vendor->delivery_mode === 'shipping' ? 'Spedito' : 'Pronto al ritiro', 'completed' => 'Consegnato'];
    $current = array_search($vendor->status->value, array_keys($steps), true);
@endphp
@if(in_array($vendor->status->value, ['cancelled', 'refunded'], true))
    <x-alert title="Consegna chiusa">{{ $vendor->status->label() }}. Lo storico dell’ordine resta consultabile.</x-alert>
@else
<nav aria-label="Avanzamento della consegna" data-status-progress>
    <ol class="grid gap-3 md:grid-cols-4">
        @foreach($steps as $key => $label)
            @php($position = $loop->index)
            @php($progress = $current === false ? 'upcoming' : ($position < $current ? 'done' : ($position === $current ? 'current' : 'upcoming')))
            <li data-step="{{ $key }}" data-step-state="{{ $progress }}" @if($progress === 'current') aria-current="step" @endif class="flex min-w-0 items-center gap-3 rounded-xl border border-line bg-surface p-3">
                <span class="flex size-9 shrink-0 items-center justify-center rounded-full {{ $progress === 'done' ? 'bg-emerald-950 text-emerald-200' : ($progress === 'current' ? 'bg-brand-50 text-brand-700' : 'bg-raised text-muted') }}" aria-hidden="true">@if($progress === 'done' || ($key === 'completed' && $progress === 'current'))<x-icon name="check-circle" />@elseif($progress === 'current')<span class="size-3 rounded-full bg-brand-600"></span>@else<span class="size-3 rounded-full border border-control"></span>@endif</span>
                <p class="min-w-0 break-words text-sm {{ $progress === 'current' ? 'font-bold text-brand-700' : 'font-medium text-muted' }}">{{ $label }}<span class="sr-only"> · {{ match($progress) { 'done' => 'Passaggio completato', 'current' => 'Stato corrente', default => 'Da raggiungere' } }}</span></p>
            </li>
        @endforeach
    </ol>
</nav>
@endif
