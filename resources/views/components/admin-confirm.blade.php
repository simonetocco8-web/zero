@props(['name','label','url','decision','variant'=>'primary','reason'=>false,'reference'=>false])
<x-button type="button" :variant="$variant" x-data x-on:click="$dispatch('open-modal', '{{ $name }}')">{{ $label }}</x-button>
<x-modal :name="$name" :title="$label"><form method="POST" action="{{ $url }}" class="space-y-4">@csrf @method('PATCH')<input type="hidden" name="decision" value="{{ $decision }}">
<p>Confermi questa operazione?</p>
@if($reason)<div><label class="field-label" for="{{ $name }}-reason">Motivazione</label><textarea class="field" id="{{ $name }}-reason" name="reason" required maxlength="1000"></textarea></div>@endif
@if($reference)<p class="text-sm">Conferma solo dopo aver eseguito il bonifico tramite la tua banca. Il gestionale non dispone pagamenti.</p><div><label class="field-label" for="{{ $name }}-reference">Riferimento disposizione bancaria</label><input class="field" id="{{ $name }}-reference" name="payment_reference" required maxlength="255" autocomplete="off"></div>@endif
<div class="flex flex-wrap gap-3"><x-button type="button" variant="secondary" x-on:click="$dispatch('close-modal', '{{ $name }}')">Annulla</x-button><x-button type="submit" :variant="$variant">Conferma</x-button></div>
</form></x-modal>
