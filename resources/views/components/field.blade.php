@props(['name', 'label', 'type' => 'text', 'value' => null, 'hint' => null])
@php
$invalid = $errors->has($name);
$displayValue = old($name, $value);
$displayValue = is_scalar($displayValue) ? $displayValue : null;
@endphp
<div>
    <label class="field-label" for="{{ $name }}">{{ $label }}</label>
    <input class="field" id="{{ $name }}" name="{{ $name }}" type="{{ $type }}" @if(!in_array($type, ['password', 'file'])) value="{{ $displayValue }}" @endif aria-invalid="{{ $invalid ? 'true' : 'false' }}" @if($invalid || $hint) aria-describedby="{{ $name }}-help" @endif {{ $attributes }}>
    @if($invalid)<p id="{{ $name }}-help" class="mt-1.5 text-sm text-red-700">{{ $errors->first($name) }}</p>
    @elseif($hint)<p id="{{ $name }}-help" class="mt-1.5 text-sm text-slate-500">{{ $hint }}</p>@endif
</div>
