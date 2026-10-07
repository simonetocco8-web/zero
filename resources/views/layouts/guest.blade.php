<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head><x-head title="Accesso" /></head>
<body>
    <main class="flex min-h-dvh flex-col items-center justify-center px-4 py-10">
        <a href="{{ route('home') }}" class="mb-8 inline-flex min-h-11 items-center"><x-brand class="text-xl" /></a>
        <div class="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">{{ $slot }}</div>
        <p class="mt-6 text-center text-xs text-slate-500">Il nuovo spazio per i tuoi materiali.</p>
    </main>
</body>
</html>
