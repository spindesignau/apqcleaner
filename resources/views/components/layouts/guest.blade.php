@props(['title' => null])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head', ['title' => $title])
</head>
<body class="min-h-dvh bg-canvas">
    {{-- Phones: compact header (small logo beside the language pill) so the form sits high on the screen.
         Tablets/desktop: a larger centred logo. --}}
    <header data-vt="header" class="relative bg-navy px-5 pt-[max(env(safe-area-inset-top),1rem)] pb-12 sm:pb-20">
        <div class="mx-auto flex max-w-md items-center justify-between gap-4 sm:justify-end">
            <x-logo variant="dark" class="w-28 sm:hidden" />
            <x-lang-pill tone="dark" />
        </div>
        <div class="mx-auto mt-6 hidden max-w-[14rem] sm:block">
            <x-logo variant="dark" class="w-full" />
        </div>
    </header>

    <main data-page class="relative mx-auto -mt-8 max-w-md px-4 pb-10 sm:-mt-12">
        <div class="card p-6 sm:p-7">
            {{ $slot }}
        </div>
    </main>
</body>
</html>
