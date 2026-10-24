@props(['name', 'label', 'url', 'icon' => null, 'errorKey' => null, 'decision' => null, 'variant' => 'primary', 'reason' => false, 'reference' => false, 'method' => 'PATCH', 'message' => 'Confermi questa operazione?'])
@php($hasErrors = old('confirmation_id') === $name && $errors->any())
<x-button type="button" :variant="$variant" :aria-label="$icon ? $label : null" :title="$icon ? $label : null" x-data x-on:click="$dispatch('open-modal', '{{ $name }}')">@if($icon)<x-icon :name="$icon" />@else{{ $label }}@endif</x-button>
<x-modal :name="$name" :title="$label" :show="$hasErrors">
    @include('components.admin-confirm-form')
</x-modal>
