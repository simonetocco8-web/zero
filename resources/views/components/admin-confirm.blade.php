@props(['name', 'label', 'url', 'errorKey' => null, 'decision' => null, 'variant' => 'primary', 'reason' => false, 'reference' => false, 'method' => 'PATCH', 'message' => 'Confermi questa operazione?'])
@php($hasErrors = old('confirmation_id') === $name && $errors->any())
<x-button type="button" :variant="$variant" x-data x-on:click="$dispatch('open-modal', '{{ $name }}')">{{ $label }}</x-button>
<x-modal :name="$name" :title="$label" :show="$hasErrors">
    @include('components.admin-confirm-form')
</x-modal>
