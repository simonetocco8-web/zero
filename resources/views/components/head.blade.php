@props(['title' => 'ZeroMagazzino', 'description' => null, 'canonical' => null, 'image' => null, 'robots' => 'index,follow'])
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="theme-color" content="#ffffff">
<link rel="icon" href="/favicon.ico">
<link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<script>
(() => {
    const apply = theme => {
        document.documentElement.dataset.theme = theme;
        document.querySelector('meta[name="theme-color"]').content = theme === 'dark' ? '#080f1c' : '#ffffff';
        document.querySelectorAll('[data-theme-toggle]').forEach(button => {
            button.setAttribute('aria-pressed', String(theme === 'dark'));
            button.setAttribute('aria-label', theme === 'dark' ? 'Attiva tema chiaro' : 'Attiva tema scuro');
            button.querySelector('[data-theme-state]').textContent = theme === 'dark' ? '☾ Dark' : '☀ Light';
        });
    };
    let theme = 'light';
    try { if (localStorage.getItem('theme') === 'dark') theme = 'dark'; } catch {}
    apply(theme);
    document.addEventListener('DOMContentLoaded', () => apply(document.documentElement.dataset.theme));
    document.addEventListener('click', event => {
        if (!event.target.closest('[data-theme-toggle]')) return;
        const next = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
        apply(next);
        try { localStorage.setItem('theme', next); } catch {}
    });
    window.addEventListener('storage', event => { if (event.key === 'theme') apply(event.newValue === 'dark' ? 'dark' : 'light'); });
})();
</script>
<title>{{ $title }} · ZeroMagazzino</title>
@if($description)<meta name="description" content="{{ $description }}">@endif
@if($canonical)<link rel="canonical" href="{{ $canonical }}"><meta property="og:url" content="{{ $canonical }}"><meta property="og:title" content="{{ $title }} · ZeroMagazzino"><meta property="og:type" content="website"><meta property="og:site_name" content="ZeroMagazzino">@if($description)<meta property="og:description" content="{{ $description }}">@endif @if($image)<meta property="og:image" content="{{ $image }}">@endif<meta name="robots" content="{{ $robots }}">@endif
@vite(['resources/css/app.css', 'resources/js/app.js'])
