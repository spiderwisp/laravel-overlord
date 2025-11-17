<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('laravel-overlord.ui.title', 'Laravel Overlord') }} - {{ config('laravel-overlord.ui.subtitle', 'Development Console') }}</title>

    <!-- Styles -->
    @if(file_exists(public_path('build/manifest.json')))
        @vite(['resources/css/app.css'])
    @else
        <link href="{{ mix('css/app.css') }}" rel="stylesheet">
    @endif

    <style>
        body {
            margin: 0;
            padding: 0;
            overflow: hidden;
            background: #1e1e1e;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
        }
        #overlord-terminal-container {
            width: 100vw;
            height: 100vh;
            position: fixed;
            top: 0;
            left: 0;
        }
    </style>
</head>
<body>
    <div id="overlord-terminal-container"></div>

    <!-- Scripts -->
    <script>
        // Configure Laravel Overlord API route
        window.overlordConfig = {
            routePrefix: '{{ config('laravel-overlord.route_prefix', 'admin/overlord') }}'
        };
    </script>

    {{-- Use published pre-compiled assets (like Horizon) --}}
    @if(file_exists(public_path('vendor/laravel-overlord/js/terminal.js')))
        <script src="{{ asset('vendor/laravel-overlord/js/terminal.js') }}"></script>
    @else
        {{-- Assets not published - show helpful error message --}}
        <div style="display: flex; align-items: center; justify-content: center; height: 100vh; color: #fff; flex-direction: column; padding: 20px; text-align: center;">
            <h1 style="color: #ff6b6b; margin-bottom: 20px;">Terminal Assets Not Found</h1>
            <p style="margin-bottom: 10px;">The Laravel Overlord terminal assets have not been published.</p>
            <p style="margin-bottom: 20px;">Please run the following command:</p>
            <pre style="background: #2d2d2d; padding: 15px; border-radius: 5px; color: #a9b7c6; margin-bottom: 20px; text-align: left;">
php artisan vendor:publish --tag=laravel-assets</pre>
            <p style="color: #888; font-size: 14px;">Or use the install command: <code style="background: #2d2d2d; padding: 2px 6px; border-radius: 3px;">php artisan overlord:install</code></p>
        </div>
    @endif
</body>
</html>

