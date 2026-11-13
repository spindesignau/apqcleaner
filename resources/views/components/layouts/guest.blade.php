@props(['title' => null])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head', ['title' => $title])
</head>
<body class="min-h-dvh bg-canvas">
    <header class="relative bg-navy px-5 pt-[max(env(safe-area-inset-top),1.25rem)] pb-20">
        <div class="mx-auto flex max-w-md justify-end">
            <x-lang-pill tone="dark" />
        </div>
        <div class="mx-auto mt-6 max-w-[17rem]">
            <x-logo variant="dark" class="w-full" />
        </div>
    </header>

    <main class="relative mx-auto -mt-12 max-w-md px-4 pb-10">
        <div class="card p-6 sm:p-7">
            {{ $slot }}
        </div>
    </main>
</body>
</html>
