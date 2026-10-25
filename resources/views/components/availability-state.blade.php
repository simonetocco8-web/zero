@props(['request', 'suffix' => 'mobile'])
@php($states = ['new' => ['In lavorazione', 'clock'], 'contacted' => ['Risposta', 'chat'], 'closed' => ['Chiusa', 'check-circle']])
<div class="space-y-2"><p class="text-sm font-semibold">{{ $states[$request->status->value][0] }}</p>
<form method="POST" action="{{ route('retailer.requests.update',$request) }}" class="flex flex-wrap gap-2" aria-label="Stato richiesta {{ $request->id }}">@csrf @method('PATCH')
@foreach($states as $value => [$label, $icon])<x-button type="submit" name="status" :value="$value" :variant="$request->status->value === $value ? 'primary' : 'secondary'" :aria-label="$label" :title="$label" :aria-pressed="$request->status->value === $value ? 'true' : 'false'"><x-icon :name="$icon" /></x-button>@endforeach
</form></div>
