<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Hospital Queue Display</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-slate-950 text-white">
    <main class="mx-auto max-w-7xl space-y-8 px-6 py-10" data-queue-board data-endpoint="{{ route('queue.display.data') }}">
        <h1 class="text-center text-4xl font-bold tracking-wide">Now Serving</h1>
        <div id="queue-error" class="hidden rounded-xl border border-rose-300 bg-rose-950 p-4 text-center text-lg text-rose-100" role="status">
            Queue status is temporarily unavailable. Please ask hospital staff for assistance.
        </div>
        <div class="grid gap-8 lg:grid-cols-2">
            @foreach (['opd' => 'OPD', 'pharmacy' => 'Pharmacy'] as $service => $label)
                <section class="rounded-3xl border border-slate-700 bg-slate-900 p-6 shadow-2xl" aria-live="polite">
                    <h2 class="mb-5 text-center text-3xl font-semibold">{{ $label }}</h2>
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3" data-queue-service="{{ $service }}">
                        @forelse ($queue[$service] as $entry)
                            <article class="rounded-2xl bg-slate-800 p-4 text-center">
                                <p class="text-3xl font-bold tracking-wider">{{ $entry['queue_number'] }}</p>
                                <p class="mt-2 text-sm font-semibold uppercase tracking-wide text-teal-300">{{ str_replace('_', ' ', $entry['status']) }}</p>
                            </article>
                        @empty
                            <p class="col-span-full py-8 text-center text-slate-400">No queue numbers yet.</p>
                        @endforelse
                    </div>
                </section>
            @endforeach
        </div>
    </main>
    <script>
        (() => {
            const board = document.querySelector('[data-queue-board]');
            const endpoint = board?.dataset.endpoint;
            const error = document.getElementById('queue-error');

            if (!endpoint) return;

            const renderService = (service, entries) => {
                const container = board.querySelector(`[data-queue-service="${service}"]`);
                container.replaceChildren();

                if (!entries.length) {
                    const empty = document.createElement('p');
                    empty.className = 'col-span-full py-8 text-center text-slate-400';
                    empty.textContent = 'No queue numbers yet.';
                    container.append(empty);
                    return;
                }

                entries.forEach((entry) => {
                    const card = document.createElement('article');
                    card.className = 'rounded-2xl bg-slate-800 p-4 text-center';
                    const number = document.createElement('p');
                    number.className = 'text-3xl font-bold tracking-wider';
                    number.textContent = entry.queue_number;
                    const status = document.createElement('p');
                    status.className = 'mt-2 text-sm font-semibold uppercase tracking-wide text-teal-300';
                    status.textContent = entry.status.replaceAll('_', ' ');
                    card.append(number, status);
                    container.append(card);
                });
            };

            const refresh = async () => {
                try {
                    const response = await fetch(endpoint, {
                        headers: { Accept: 'application/json' },
                        cache: 'no-store',
                    });
                    if (!response.ok) throw new Error('Queue data request failed.');
                    const data = await response.json();
                    renderService('opd', data.opd || []);
                    renderService('pharmacy', data.pharmacy || []);
                    error.classList.add('hidden');
                } catch {
                    error.classList.remove('hidden');
                }
            };

            refresh();
            window.setInterval(refresh, 10000);
        })();
    </script>
</body>
</html>
