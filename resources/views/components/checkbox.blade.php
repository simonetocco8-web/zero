@props(['name', 'label', 'checked' => false])
<div>
<label class="inline-flex min-h-11 items-center gap-3 text-sm" for="{{ $name }}">
    <input id="{{ $name }}" name="{{ $name }}" type="checkbox" @checked($checked) {{ $attributes->class(['size-5 rounded border-slate-300 text-brand-600 focus:ring-brand-600']) }} @if($errors->has($name)) aria-invalid="true" aria-describedby="{{ $name }}-error" @endif>
    <span>{{ $label }}</span>
</label>
@error($name)<p id="{{ $name }}-error" class="text-sm text-red-700">{{ $message }}</p>@enderror
</div>
