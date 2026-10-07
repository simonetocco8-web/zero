@props(['name', 'label', 'checked' => false])
<div>
<input type="hidden" name="{{ $name }}" value="0">
<label class="inline-flex min-h-11 items-center gap-3 text-sm" for="{{ $name }}">
    <input id="{{ $name }}" name="{{ $name }}" type="checkbox" value="1" @checked(old($name, $checked)) {{ $attributes->class(['size-5 rounded border-slate-300 text-brand-600 focus:ring-brand-600']) }} @if($errors->has($name)) aria-invalid="true" aria-describedby="{{ $name }}-error" @endif>
    <span>{{ $label }}</span>
</label>
@error($name)<p id="{{ $name }}-error" class="text-sm text-red-700">{{ $message }}</p>@enderror
</div>
