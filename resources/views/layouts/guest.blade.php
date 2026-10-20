<!DOCTYPE html>
<html data-theme="light" lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head><x-head :title="$title" /></head>
<body>
    <div class="absolute right-4 top-4"><x-theme-toggle /></div>
    <a href="#main-content" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 focus:bg-surface focus:p-3">Vai al contenuto</a>
    <main id="main-content" tabindex="-1" class="flex min-h-dvh flex-col items-center justify-center px-4 py-10">
        <a href="{{ route('home') }}" class="mb-8 inline-flex min-h-11 items-center"><x-brand class="text-xl" /></a>
        <div class="w-full max-w-md rounded-2xl border border-line bg-surface p-6 shadow-sm sm:p-8">{{ $slot }}</div>
        <p class="mt-6 text-center text-xs text-muted">Il nuovo spazio per i tuoi materiali.</p>
    </main>
</body>
</html>
