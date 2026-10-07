@props(['name', 'label'])
<div>
    <label class="field-label" for="{{ $name }}">{{ $label }}</label>
    <select id="{{ $name }}" name="{{ $name }}" {{ $attributes->class(['field']) }} aria-invalid="{{ $errors->has($name) ? 'true' : 'false' }}" @if($errors->has($name)) aria-describedby="{{ $name }}-error" @endif>{{ $slot }}</select>
    @error($name)<p id="{{ $name }}-error" class="mt-1.5 text-sm text-red-700">{{ $message }}</p>@enderror
</div>
