@props(['statuses'])
<form method="GET" class="my-5 grid min-w-0 gap-3 rounded-2xl border border-slate-200 bg-white p-4 sm:grid-cols-2 xl:grid-cols-5">
<div><label class="field-label" for="status">Stato</label><select class="field" id="status" name="status"><option value="">Tutti</option>@foreach($statuses as $value=>$label)<option value="{{ $value }}" @selected(request('status')===$value)>{{ $label }}</option>@endforeach</select></div>
<x-field name="company" label="Azienda" :value="request('company')" />
<x-field name="from" label="Dal" type="date" :value="request('from')" />
<x-field name="to" label="Al" type="date" :value="request('to')" />
<div class="flex flex-wrap items-end gap-2"><x-button type="submit">Filtra</x-button><x-button variant="secondary" :href="url()->current()">Azzera</x-button></div>
</form>
@if($errors->any())<x-alert variant="danger">{{ $errors->first() }}</x-alert>@endif
@if(session('status'))<x-alert variant="success">{{ session('status') }}</x-alert>@endif
