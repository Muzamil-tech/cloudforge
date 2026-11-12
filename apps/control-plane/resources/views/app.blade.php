<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="theme-color" content="#f8fafc">
        <script>
            (function () {
                try {
                    var stored = window.localStorage.getItem('cf-theme');
                    var dark = stored === 'dark';
                    document.documentElement.classList.toggle('dark', dark);
                    document.querySelector('meta[name="theme-color"]').setAttribute('content', dark ? '#0b0f14' : '#f8fafc');
                } catch (error) {
                    document.documentElement.classList.remove('dark');
                }
            })();
        </script>
        <title inertia>{{ config('cloudforge.name', 'CloudForge') }}</title>
        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.jsx'])
        @inertiaHead
    </head>
    <body class="min-h-screen bg-[var(--cf-bg)] font-sans text-[var(--cf-text)] antialiased">
        @inertia
    </body>
</html>
