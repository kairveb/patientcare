<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') — {{ config('app.name', 'HIMS') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-50 text-slate-800">
    <main class="mx-auto max-w-4xl px-4 py-10 sm:px-6">
        <a href="{{ auth()->check() ? route('dashboard') : route('login') }}" class="text-sm font-semibold text-teal-800 underline">Back to HIMS</a>
        <article class="prose prose-slate mt-5 max-w-none rounded-2xl border bg-white p-6 shadow-sm sm:p-10">
            @yield('content')
        </article>
        <footer class="mt-6 flex gap-4 text-sm">
            <a class="text-teal-800 underline" href="{{ route('privacy.notice') }}">Privacy Notice</a>
            <a class="text-teal-800 underline" href="{{ route('privacy.terms') }}">Terms and Agreement</a>
        </footer>
    </main>
</body>
</html>
