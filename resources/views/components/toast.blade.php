<div x-data="{ visible: false, message: '' }" x-on:toast.window="message = $event.detail; visible = true" x-show="visible" x-cloak role="status" aria-live="polite" class="fixed inset-x-4 top-4 z-50 mx-auto flex max-w-md items-center justify-between gap-3 rounded-xl border border-emerald-200 bg-white p-4 shadow-lg">
    <span class="text-sm text-emerald-900" x-text="message"></span><button type="button" class="btn btn-secondary" x-on:click="visible = false" aria-label="Chiudi notifica">×</button>
</div>
