@props(['name', 'title', 'show' => false])
<dialog x-data="{ open() { $el.showModal() }, close() { $el.close() } }" @if($show) x-init="open()" @endif x-on:open-modal.window="if ($event.detail === @js($name)) open()" x-on:close-modal.window="if ($event.detail === @js($name)) close()" x-on:click="if ($event.target === $el) close()" aria-labelledby="{{ $name }}-title" class="m-auto w-[calc(100%-2rem)] max-w-lg rounded-2xl border-0 p-6 shadow-xl" {{ $attributes }}>
    <div class="mb-4 flex items-start justify-between gap-3"><h2 id="{{ $name }}-title">{{ $title }}</h2><button type="button" class="btn btn-secondary shrink-0" x-on:click="close()" aria-label="Chiudi finestra">×</button></div>
    {{ $slot }}
</dialog>
