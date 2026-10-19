@props(['title' => 'ZeroMagazzino', 'description' => null, 'canonical' => null, 'image' => null, 'robots' => 'index,follow'])
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="theme-color" content="#080f1c">
<title>{{ $title }} · ZeroMagazzino</title>
@if($description)<meta name="description" content="{{ $description }}">@endif
@if($canonical)<link rel="canonical" href="{{ $canonical }}"><meta property="og:url" content="{{ $canonical }}"><meta property="og:title" content="{{ $title }} · ZeroMagazzino"><meta property="og:type" content="website"><meta property="og:site_name" content="ZeroMagazzino">@if($description)<meta property="og:description" content="{{ $description }}">@endif @if($image)<meta property="og:image" content="{{ $image }}">@endif<meta name="robots" content="{{ $robots }}">@endif
@vite(['resources/css/app.css', 'resources/js/app.js'])
