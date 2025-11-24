<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('laravel-overlord.ui.title', 'Laravel Overlord') }} - {{ config('laravel-overlord.ui.subtitle', 'Development Console') }}</title>

    @vite(['resources/js/app.js'])
    
    <style>
        body {
            margin: 0;
            padding: 0;
            overflow: hidden;
            background: #1e1e1e;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
        }
        
        #app {
            width: 100vw;
            height: 100vh;
        }
    </style>
    
    <script>
        // Set up route prefix for useOverlordApi before Vue loads
        window.overlordConfig = {
            routePrefix: '{{ config('laravel-overlord.route_prefix', 'admin/overlord') }}'
        };
    </script>
</head>
<body>
    <div id="app">
        <developer-terminal :visible="true" :floating="false" />
    </div>

    <script type="module">
        import { createApp } from 'vue';
        import DeveloperTerminal from '@/vendor/laravel-overlord/Components/DeveloperTerminal.vue';

        const app = createApp({
            components: {
                DeveloperTerminal
            }
        });

        app.mount('#app');
    </script>
</body>
</html>

