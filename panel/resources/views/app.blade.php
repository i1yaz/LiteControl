@php
    $activeTheme = app(\App\Services\ThemeManager::class)->resolve(request());
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full" data-theme="{{ $activeTheme['id'] }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title inertia>{{ config('app.name', 'LiteControl') }}</title>

    @if(!empty($activeTheme['fonts']))
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        @foreach($activeTheme['fonts'] as $fontUrl)
            <link href="{{ $fontUrl }}" rel="stylesheet">
        @endforeach
    @endif

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @inertiaHead
</head>
<body class="h-full antialiased theme-body">
    @inertia
</body>
</html>
