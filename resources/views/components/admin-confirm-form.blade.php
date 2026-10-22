<form method="POST" action="{{ $url }}" class="space-y-4">
@csrf @method($method)
<input type="hidden" name="confirmation_id" value="{{ $name }}">
@if($decision)<input type="hidden" name="decision" value="{{ $decision }}">@endif
<p>{{ $message }}</p>
@if($reason)
<div><label class="field-label" for="{{ $name }}-reason">Motivazione</label>
<textarea class="field" id="{{ $name }}-reason" name="reason" required maxlength="1000" @if($hasErrors && $errors->has('reason')) aria-invalid="true" aria-describedby="{{ $name }}-reason-error" @endif>{{ $hasErrors ? old('reason') : '' }}</textarea>
@if($hasErrors && $errors->has('reason'))<p id="{{ $name }}-reason-error" class="mt-2 text-sm text-danger">{{ $errors->first('reason') }}</p>@endif</div>
@endif
@if($reference)
<p class="text-sm">Conferma solo dopo aver eseguito il bonifico tramite la tua banca. Il gestionale non dispone pagamenti.</p>
<div><label class="field-label" for="{{ $name }}-reference">Riferimento disposizione bancaria</label>
<input class="field" id="{{ $name }}-reference" name="payment_reference" required maxlength="191" autocomplete="off" value="{{ $hasErrors ? old('payment_reference') : '' }}" @if($hasErrors && $errors->has('payment_reference')) aria-invalid="true" aria-describedby="{{ $name }}-reference-error" @endif>
@if($hasErrors && $errors->has('payment_reference'))<p id="{{ $name }}-reference-error" class="mt-2 text-sm text-danger">{{ $errors->first('payment_reference') }}</p>@endif</div>
@endif
<div class="flex flex-wrap gap-3"><x-button type="button" variant="secondary" x-on:click="$dispatch('close-modal', '{{ $name }}')">Annulla</x-button><x-button type="submit" :variant="$variant">Conferma</x-button></div>
</form>
