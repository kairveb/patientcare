<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light dark">
    <script>
      window.__APP_CONFIG__ = {
        apiBaseUrl: "{{ rtrim(config('app.url', env('APP_URL', '')), '/') }}"
      };
    </script>
    <title>@yield('title', 'Sign in | HIMS')</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    <script src="{{ asset('assets/js/core/theme-boot.js') }}"></script>
    <link rel="stylesheet" href="{{ asset('assets/css/style.css') }}">
    @stack('styles')
    <link rel="stylesheet" href="{{ asset('assets/css/components/typography-accessibility.css') }}">
  </head>
  <body class="login-page" data-module="@yield('module', 'auth')" data-page="@yield('page', 'login')">
    @yield('content')
    <footer class="mx-auto flex justify-center gap-5 px-4 py-5 text-sm text-slate-700">
      <a class="underline" href="{{ route('privacy.notice') }}">Privacy Notice</a>
      <a class="underline" href="{{ route('privacy.terms') }}">Terms and Agreement</a>
    </footer>
    @stack('scripts')
  </body>
</html>
