<section wire:poll.60s aria-labelledby="home-attention-title" data-attention>
    <x-ui.card padding="p-0">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-4 py-3">
            <h2 id="home-attention-title" class="text-base font-semibold text-slate-900">{{ __('Needs attention') }}</h2>
            <p class="text-xs text-slate-500">
                {{ __('checked at :time', ['time' => $checkedAt]) }}
                <span wire:loading style="display: none;" class="ml-1 text-primary-700">{{ __('Updating...') }}</span>
            </p>
        </div>
        @if ($noShops)
            <p class="px-4 py-4 text-sm text-slate-500">{{ __('No shops are assigned to you. Ask an admin.') }}</p>
        @elseif ($rows === [])
            <p class="flex items-center gap-2 px-4 py-4 text-sm text-slate-700" data-attention-clear>
                <x-icon name="check" class="h-5 w-5 shrink-0 text-emerald-600" />
                <span><span class="font-medium">{{ __('All clear') }}</span> — {{ __('nothing needs your attention right now.') }}</span>
            </p>
        @else
            <ul class="divide-y divide-slate-100">
                @foreach ($rows as $row)
                    <li data-attention-row="{{ $row['key'] }}" data-severity="{{ $row['severity'] }}">
                        <a href="{{ $row['url'] }}" class="flex items-start gap-3 px-4 py-3 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary-500">
                            <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-md {{ $row['severity'] === 'red' ? 'bg-red-50 text-red-700' : 'bg-amber-50 text-amber-700' }}">
                                <x-icon :name="$row['icon']" class="h-5 w-5" />
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm text-slate-900">{{ $row['text'] }}</span>
                                <span class="mt-0.5 inline-flex items-center gap-1 text-xs font-medium {{ $row['severity'] === 'red' ? 'text-red-700' : 'text-amber-700' }}">
                                    {{ $row['severity'] === 'red' ? '● '.__('Urgent') : '▲ '.__('Warning') }}
                                </span>
                            </span>
                            <span class="shrink-0 rounded-md bg-slate-100 px-2 py-0.5 text-xs font-semibold tabular-nums text-slate-700">{{ $row['count'] }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>
</section>
