<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'Orbit') }}</title>
    <script>
        // Sync dark mode class with system preference
        if (window.matchMedia('(prefers-color-scheme: dark)').matches) {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (e) => {
            document.documentElement.classList.toggle('dark', e.matches);
        });
    </script>
    @vite(['resources/js/app.ts', 'resources/css/app.css'])
    @inertiaHead
</head>
<body class="bg-zinc-950 antialiased">
    @inertia
</body>
</html>
