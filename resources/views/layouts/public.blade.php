<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <meta
        name="csrf-token"
        content="{{ csrf_token() }}"
    >

    <title>
        {{ $title ?? 'Secure Document Upload' }} | SmartServe
    </title>

    {{--
        Load the same compiled frontend assets used by the staff application.
        The public upload page still uses its own layout and contains no
        authenticated navigation or internal system controls.
    --}}
    @vite([
        'resources/css/app.css',
        'resources/js/app.js',
    ])

    @livewireStyles
</head>

<body
    class="min-h-screen bg-zinc-50 text-zinc-900 antialiased dark:bg-zinc-950 dark:text-white"
>
    <div class="flex min-h-screen flex-col">
        <header
            class="border-b border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900"
        >
            <div
                class="mx-auto flex w-full max-w-5xl items-center justify-between px-4 py-4 sm:px-6 lg:px-8"
            >
                <a
                    href="{{ url('/') }}"
                    class="flex items-center gap-3"
                >
                    <div
                        class="flex h-10 w-10 items-center justify-center rounded-xl bg-zinc-900 text-sm font-bold text-white dark:bg-white dark:text-zinc-900"
                    >
                        SS
                    </div>

                    <div>
                        <div class="font-semibold">
                            SmartServe
                        </div>

                        <div
                            class="text-xs text-zinc-500 dark:text-zinc-400"
                        >
                            Secure Document Collection
                        </div>
                    </div>
                </a>

                <div
                    class="hidden text-sm text-zinc-500 dark:text-zinc-400 sm:block"
                >
                    Secure customer upload
                </div>
            </div>
        </header>

        <main class="flex-1">
            {{--
                The public Livewire page is inserted here.
                Only request-specific customer information should be displayed.
            --}}
            {{ $slot }}
        </main>

        <footer
            class="border-t border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900"
        >
            <div
                class="mx-auto flex w-full max-w-5xl flex-col gap-2 px-4 py-5 text-sm text-zinc-500 sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:px-8 dark:text-zinc-400"
            >
                <p>
                    © {{ now()->year }} SmartServe
                </p>

                <p>
                    Documents are transmitted through a secure request link.
                </p>
            </div>
        </footer>
    </div>

    @livewireScripts
</body>
</html>
