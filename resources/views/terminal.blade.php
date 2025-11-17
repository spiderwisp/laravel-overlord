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

    @if(file_exists(public_path('build/manifest.json')))
        @vite(['resources/js/vendor/laravel-overlord/terminal.js'])
    @elseif(file_exists(public_path('js/vendor/laravel-overlord/terminal.js')))
        <script src="{{ mix('js/vendor/laravel-overlord/terminal.js') }}"></script>
    @else
        {{-- Fallback: Use app.js if terminal.js is not compiled --}}
        <script src="{{ mix('js/app.js') }}"></script>
        <script>
            // Initialize terminal after app.js loads
            document.addEventListener('DOMContentLoaded', function() {
                setTimeout(function() {
                    if (window.Vue && window.Vue.createApp) {
                        // Try to import DeveloperTerminal from the published location
                        // This requires the component to be available globally or via dynamic import
                        console.warn('Terminal.js not found. Please compile terminal.js or use the component in your app.');
                    }
                }, 1000);
            });
        </script>
    @endif
</body>
</html>

