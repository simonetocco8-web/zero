@props(['title', 'description', 'icon' => 'box'])
<div {{ $attributes->class(['flex flex-col items-center px-2 py-12 text-center sm:py-16']) }}>
    <span class="mb-5 inline-flex size-16 items-center justify-center rounded-2xl bg-brand-50 text-brand-600"><x-icon :name="$icon" class="size-8" /></span>
    <h2>{{ $title }}</h2><p class="mt-3 max-w-md text-sm leading-relaxed text-slate-500">{{ $description }}</p>
    @if($slot->isNotEmpty())<div class="mt-6">{{ $slot }}</div>@endif
</div>
