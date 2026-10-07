@props(['variant' => 'primary', 'href' => null, 'type' => 'button'])
@php($style = match($variant) { 'danger' => 'btn-danger', 'secondary' => 'btn-secondary', default => 'btn-primary' })
@if($href)
<a href="{{ $href }}" {{ $attributes->class(['btn', $style]) }}>{{ $slot }}</a>
@else
<button type="{{ $type }}" {{ $attributes->class(['btn', $style]) }}>{{ $slot }}</button>
@endif
