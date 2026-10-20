<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title inertia>{{ \Illuminate\Support\Facades\Schema::hasTable('settings') ? (app(\App\Services\SettingsService::class)->get('app.name') ?? config('app.name', 'TPDA e-Sheba')) : config('app.name', 'TPDA e-Sheba') }}</title>
        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.jsx'])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
