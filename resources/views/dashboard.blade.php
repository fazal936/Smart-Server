<x-layouts::app :title="__('Dashboard')">
    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-semibold">
                SmartServe Dashboard
            </h1>

            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                Welcome, {{ auth()->user()->name }}.
            </p>
        </div>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            @foreach ([
                ['label' => 'Active Requests', 'value' => '0'],
                ['label' => 'Waiting for Documents', 'value' => '0'],
                ['label' => 'Under Review', 'value' => '0'],
                ['label' => 'Completed This Month', 'value' => '0'],
            ] as $card)
                <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">
                        {{ $card['label'] }}
                    </p>

                    <p class="mt-2 text-3xl font-semibold">
                        {{ $card['value'] }}
                    </p>
                </div>
            @endforeach
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <h2 class="font-semibold">
                    Requests requiring attention
                </h2>

                <p class="mt-4 text-sm text-zinc-500">
                    No requests require attention yet.
                </p>
            </div>

            <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <h2 class="font-semibold">
                    Recent activity
                </h2>

                <p class="mt-4 text-sm text-zinc-500">
                    No activity has been recorded yet.
                </p>
            </div>
        </div>
    </div>
</x-layouts::app>
