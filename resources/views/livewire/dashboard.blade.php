<div>
    <x-ui.page-header :title="__('Dashboard')" :subtitle="__('Overview of shops, stock and receipts will live here.')" />

    <div class="space-y-6">
        {{-- Chart 1: Stock by shop — bar (primary) + donut (secondary) --}}
        <x-ui.card>
            <div x-data="{
                view: 'chart',
                tip: { show: false, x: 0, y: 0, name: '', value: '', el: null },
                showTip(e) {
                    const el = e.currentTarget;
                    const surface = el.closest('[data-chart-surface]');
                    const rect = surface.getBoundingClientRect();
                    const elRect = el.getBoundingClientRect();
                    const x = (e.clientX ?? (elRect.left + elRect.width / 2)) - rect.left;
                    const y = (e.clientY ?? elRect.top) - rect.top;
                    this.tip = { show: true, x, y, name: el.dataset.name, value: el.dataset.value, el };
                },
                hideTip() { this.tip.show = false; this.tip.el = null; },
            }">
                <div class="mb-4 flex items-start justify-between gap-3">
                    <div>
                        <h2 class="text-base font-semibold text-[#0b0b0b]">{{ __('Stock by shop') }}</h2>
                        <p class="text-sm text-[#52514e]">{{ __('Total on-hand quantity per shop.') }}</p>
                    </div>
                    <button
                        type="button"
                        @click="view = view === 'chart' ? 'table' : 'chart'"
                        class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-sand-50"
                    >
                        <x-icon name="clipboard" class="h-4 w-4" />
                        <span x-text="view === 'chart' ? @js(__('Show table')) : @js(__('Show chart'))"></span>
                    </button>
                </div>

                @if(empty($shopRows))
                    <x-ui.empty-state :title="__('No stock records yet')" :description="__('Stock records are created automatically from 1C sync.')" />
                @else
                    {{-- Chart view --}}
                    <div x-show="view === 'chart'">
                        <div data-chart-surface class="relative overflow-x-auto rounded-lg border border-[#e1e0d9] bg-[#fcfcfb] p-4">
                            <div class="space-y-3">
                                @foreach($shopRows as $row)
                                    <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:gap-3">
                                        <div class="text-sm font-medium text-[#0b0b0b] sm:w-32 sm:shrink-0 sm:truncate md:w-40" title="{{ $row['name'] }}">
                                            {{ $row['name'] }}
                                        </div>
                                        <div class="relative h-9 flex-1">
                                            <div class="pointer-events-none absolute inset-0 flex items-stretch justify-between">
                                                @foreach ([0, 25, 50, 75, 100] as $tick)
                                                    <span class="w-px bg-[#e1e0d9]"></span>
                                                @endforeach
                                            </div>
                                            <div class="relative flex h-full items-center">
                                                <div
                                                    tabindex="0"
                                                    data-name="{{ $row['name'] }}"
                                                    data-value="{{ $row['label'] }}"
                                                    @pointermove="showTip($event)"
                                                    @pointerleave="hideTip()"
                                                    @focus="showTip($event)"
                                                    @blur="hideTip()"
                                                    :class="tip.el === $el ? 'brightness-90' : ''"
                                                    class="flex h-6 items-center rounded-r-[4px] transition focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-1"
                                                    style="width: {{ max($row['percent'], 1) }}%; background-color: {{ $barColor }};"
                                                >
                                                    @if($row['percent'] >= 25)
                                                        <span class="ml-auto mr-2 truncate text-xs font-semibold text-white">{{ $row['label'] }}</span>
                                                    @endif
                                                </div>
                                                @if($row['percent'] < 25)
                                                    <span class="ml-2 shrink-0 text-xs font-medium text-[#52514e]">{{ $row['label'] }}</span>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>

                            <div x-show="tip.show" x-cloak
                                class="pointer-events-none absolute z-20 -translate-x-1/2 -translate-y-full whitespace-nowrap rounded-md bg-[#0b0b0b] px-2 py-1 text-xs text-white shadow-lg"
                                :style="`left: ${tip.x}px; top: ${Math.max(tip.y - 8, 0)}px;`"
                            >
                                <p x-text="tip.name" class="font-medium"></p>
                                <p x-text="tip.value"></p>
                            </div>
                        </div>

                        @if(!empty($donut['segments']))
                            <div class="mt-6 border-t border-[#e1e0d9] pt-6">
                                <h3 class="mb-3 text-sm font-semibold text-[#0b0b0b]">{{ __('Share of total') }}</h3>
                                <div data-chart-surface class="relative flex flex-col items-center gap-6 sm:flex-row sm:justify-center">
                                    <svg viewBox="0 0 160 160" class="h-40 w-40 shrink-0">
                                        <g transform="rotate(-90 80 80)">
                                            @foreach($donut['segments'] as $segment)
                                                <circle
                                                    tabindex="0"
                                                    data-name="{{ $segment['name'] }}"
                                                    data-value="{{ $segment['label'] }}"
                                                    @pointermove="showTip($event)"
                                                    @pointerleave="hideTip()"
                                                    @focus="showTip($event)"
                                                    @blur="hideTip()"
                                                    :class="tip.el === $el ? 'opacity-80' : ''"
                                                    class="cursor-pointer transition focus:outline-none"
                                                    cx="80" cy="80" r="{{ $donut['radius'] }}"
                                                    fill="none"
                                                    stroke="{{ $segment['color'] }}"
                                                    stroke-width="24"
                                                    stroke-dasharray="{{ $segment['dasharray'] }}"
                                                    stroke-dashoffset="{{ $segment['dashoffset'] }}"
                                                ></circle>
                                            @endforeach
                                        </g>
                                    </svg>

                                    <ul class="w-full space-y-1.5 sm:w-auto">
                                        @foreach($donut['segments'] as $segment)
                                            <li class="flex items-center gap-2 text-sm">
                                                <span class="h-3 w-3 shrink-0 rounded-sm" style="background-color: {{ $segment['color'] }}"></span>
                                                <span class="min-w-0 flex-1 truncate text-[#0b0b0b]">{{ $segment['name'] }}</span>
                                                <span class="shrink-0 font-medium text-[#52514e]">{{ $segment['label'] }}</span>
                                            </li>
                                        @endforeach
                                    </ul>

                                    <div x-show="tip.show" x-cloak
                                        class="pointer-events-none absolute z-20 -translate-x-1/2 -translate-y-full whitespace-nowrap rounded-md bg-[#0b0b0b] px-2 py-1 text-xs text-white shadow-lg"
                                        :style="`left: ${tip.x}px; top: ${Math.max(tip.y - 8, 0)}px;`"
                                    >
                                        <p x-text="tip.name" class="font-medium"></p>
                                        <p x-text="tip.value"></p>
                                    </div>
                                </div>
                            </div>
                        @endif
                    </div>

                    {{-- Table view --}}
                    <div x-show="view === 'table'" x-cloak class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200">
                            <thead class="bg-sand-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Shop') }}</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Qty') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach($shopRows as $row)
                                    <tr>
                                        <td class="px-4 py-3 text-sm font-medium text-slate-900">{{ $row['name'] }}</td>
                                        <td class="px-4 py-3 text-sm text-slate-500">{{ $row['label'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </x-ui.card>

        {{-- Chart 2: Stock by group — bar only --}}
        <x-ui.card>
            <div x-data="{
                view: 'chart',
                tip: { show: false, x: 0, y: 0, name: '', value: '', el: null },
                showTip(e) {
                    const el = e.currentTarget;
                    const surface = el.closest('[data-chart-surface]');
                    const rect = surface.getBoundingClientRect();
                    const elRect = el.getBoundingClientRect();
                    const x = (e.clientX ?? (elRect.left + elRect.width / 2)) - rect.left;
                    const y = (e.clientY ?? elRect.top) - rect.top;
                    this.tip = { show: true, x, y, name: el.dataset.name, value: el.dataset.value, el };
                },
                hideTip() { this.tip.show = false; this.tip.el = null; },
            }">
                <div class="mb-4 flex items-start justify-between gap-3">
                    <div>
                        <h2 class="text-base font-semibold text-[#0b0b0b]">{{ __('Stock by group') }}</h2>
                        <p class="text-sm text-[#52514e]">{{ __('Total on-hand quantity per product group.') }}</p>
                    </div>
                    <button
                        type="button"
                        @click="view = view === 'chart' ? 'table' : 'chart'"
                        class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-sand-50"
                    >
                        <x-icon name="clipboard" class="h-4 w-4" />
                        <span x-text="view === 'chart' ? @js(__('Show table')) : @js(__('Show chart'))"></span>
                    </button>
                </div>

                @if(empty($groupRows))
                    <x-ui.empty-state :title="__('No stock records yet')" :description="__('Stock records are created automatically from 1C sync.')" />
                @else
                    <div x-show="view === 'chart'" data-chart-surface class="relative overflow-x-auto rounded-lg border border-[#e1e0d9] bg-[#fcfcfb] p-4">
                        <div class="space-y-3">
                            @foreach($groupRows as $row)
                                <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:gap-3">
                                    <div class="text-sm font-medium text-[#0b0b0b] sm:w-32 sm:shrink-0 sm:truncate md:w-40" title="{{ $row['name'] }}">
                                        {{ $row['name'] }}
                                    </div>
                                    <div class="relative h-9 flex-1">
                                        <div class="pointer-events-none absolute inset-0 flex items-stretch justify-between">
                                            @foreach ([0, 25, 50, 75, 100] as $tick)
                                                <span class="w-px bg-[#e1e0d9]"></span>
                                            @endforeach
                                        </div>
                                        <div class="relative flex h-full items-center">
                                            <div
                                                tabindex="0"
                                                data-name="{{ $row['name'] }}"
                                                data-value="{{ $row['label'] }}"
                                                @pointermove="showTip($event)"
                                                @pointerleave="hideTip()"
                                                @focus="showTip($event)"
                                                @blur="hideTip()"
                                                :class="tip.el === $el ? 'brightness-90' : ''"
                                                class="flex h-6 items-center rounded-r-[4px] transition focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-1"
                                                style="width: {{ max($row['percent'], 1) }}%; background-color: {{ $barColor }};"
                                            >
                                                @if($row['percent'] >= 25)
                                                    <span class="ml-auto mr-2 truncate text-xs font-semibold text-white">{{ $row['label'] }}</span>
                                                @endif
                                            </div>
                                            @if($row['percent'] < 25)
                                                <span class="ml-2 shrink-0 text-xs font-medium text-[#52514e]">{{ $row['label'] }}</span>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <div x-show="tip.show" x-cloak
                            class="pointer-events-none absolute z-20 -translate-x-1/2 -translate-y-full whitespace-nowrap rounded-md bg-[#0b0b0b] px-2 py-1 text-xs text-white shadow-lg"
                            :style="`left: ${tip.x}px; top: ${Math.max(tip.y - 8, 0)}px;`"
                        >
                            <p x-text="tip.name" class="font-medium"></p>
                            <p x-text="tip.value"></p>
                        </div>
                    </div>

                    <div x-show="view === 'table'" x-cloak class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200">
                            <thead class="bg-sand-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Group') }}</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Qty') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach($groupRows as $row)
                                    <tr>
                                        <td class="px-4 py-3 text-sm font-medium text-slate-900">{{ $row['name'] }}</td>
                                        <td class="px-4 py-3 text-sm text-slate-500">{{ $row['label'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </x-ui.card>

        {{-- Chart 3: Stock by group + shop — stacked bar --}}
        <x-ui.card>
            <div x-data="{
                view: 'chart',
                tip: { show: false, x: 0, y: 0, name: '', value: '', el: null },
                showTip(e) {
                    const el = e.currentTarget;
                    const surface = el.closest('[data-chart-surface]');
                    const rect = surface.getBoundingClientRect();
                    const elRect = el.getBoundingClientRect();
                    const x = (e.clientX ?? (elRect.left + elRect.width / 2)) - rect.left;
                    const y = (e.clientY ?? elRect.top) - rect.top;
                    this.tip = { show: true, x, y, name: el.dataset.name, value: el.dataset.value, el };
                },
                hideTip() { this.tip.show = false; this.tip.el = null; },
            }">
                <div class="mb-4 flex items-start justify-between gap-3">
                    <div>
                        <h2 class="text-base font-semibold text-[#0b0b0b]">{{ __('Stock by group and shop') }}</h2>
                        <p class="text-sm text-[#52514e]">{{ __('Total on-hand quantity broken down by group and shop.') }}</p>
                    </div>
                    <button
                        type="button"
                        @click="view = view === 'chart' ? 'table' : 'chart'"
                        class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-sand-50"
                    >
                        <x-icon name="clipboard" class="h-4 w-4" />
                        <span x-text="view === 'chart' ? @js(__('Show table')) : @js(__('Show chart'))"></span>
                    </button>
                </div>

                @if(empty($groupShop['rows']))
                    <x-ui.empty-state :title="__('No stock records yet')" :description="__('Stock records are created automatically from 1C sync.')" />
                @else
                    <div x-show="view === 'chart'">
                        <ul class="mb-4 flex flex-wrap gap-x-4 gap-y-1.5">
                            @foreach($groupShop['legend'] as $legendItem)
                                <li class="flex items-center gap-1.5 text-xs">
                                    <span class="h-3 w-3 shrink-0 rounded-sm" style="background-color: {{ $legendItem['color'] }}"></span>
                                    <span class="text-[#52514e]">{{ $legendItem['name'] }}</span>
                                </li>
                            @endforeach
                        </ul>

                        <div data-chart-surface class="relative overflow-x-auto rounded-lg border border-[#e1e0d9] bg-[#fcfcfb] p-4">
                            <div class="space-y-3">
                                @foreach($groupShop['rows'] as $row)
                                    <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:gap-3">
                                        <div class="text-sm font-medium text-[#0b0b0b] sm:w-32 sm:shrink-0 sm:truncate md:w-40" title="{{ $row['name'] }}">
                                            {{ $row['name'] }}
                                        </div>
                                        <div class="relative h-9 flex-1">
                                            <div class="pointer-events-none absolute inset-0 flex items-stretch justify-between">
                                                @foreach ([0, 25, 50, 75, 100] as $tick)
                                                    <span class="w-px bg-[#e1e0d9]"></span>
                                                @endforeach
                                            </div>
                                            <div class="relative flex h-full items-center">
                                                <div class="flex h-6 gap-[2px] overflow-hidden rounded-r-[4px]" style="width: {{ max($row['bar_percent'], 0) }}%; background-color: #fcfcfb;">
                                                    @foreach($row['segments'] as $segment)
                                                        @php $trackPercent = $row['bar_percent'] * $segment['percent_of_bar'] / 100; @endphp
                                                        <div
                                                            tabindex="0"
                                                            data-name="{{ $segment['name'] }}"
                                                            data-value="{{ $segment['label'] }}"
                                                            @pointermove="showTip($event)"
                                                            @pointerleave="hideTip()"
                                                            @focus="showTip($event)"
                                                            @blur="hideTip()"
                                                            :class="tip.el === $el ? 'brightness-90' : ''"
                                                            class="flex h-full items-center justify-end overflow-hidden transition focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-1"
                                                            style="flex: 0 0 {{ $segment['percent_of_bar'] }}%; background-color: {{ $segment['color'] }};"
                                                        >
                                                            @if($trackPercent >= 12)
                                                                <span class="truncate px-1 text-[10px] font-semibold text-white">{{ $segment['label'] }}</span>
                                                            @endif
                                                        </div>
                                                    @endforeach
                                                </div>
                                                <span class="ml-2 shrink-0 text-xs font-medium text-[#52514e]">{{ $row['total_label'] }}</span>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>

                            <div x-show="tip.show" x-cloak
                                class="pointer-events-none absolute z-20 -translate-x-1/2 -translate-y-full whitespace-nowrap rounded-md bg-[#0b0b0b] px-2 py-1 text-xs text-white shadow-lg"
                                :style="`left: ${tip.x}px; top: ${Math.max(tip.y - 8, 0)}px;`"
                            >
                                <p x-text="tip.name" class="font-medium"></p>
                                <p x-text="tip.value"></p>
                            </div>
                        </div>
                    </div>

                    <div x-show="view === 'table'" x-cloak class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200">
                            <thead class="bg-sand-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Group') }}</th>
                                    @foreach($groupShop['legend'] as $legendItem)
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $legendItem['name'] }}</th>
                                    @endforeach
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Total') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach($groupShop['rows'] as $row)
                                    <tr>
                                        <td class="px-4 py-3 text-sm font-medium text-slate-900">{{ $row['name'] }}</td>
                                        @foreach($row['cells'] as $cell)
                                            <td class="px-4 py-3 text-sm text-slate-500">{{ $cell['label'] }}</td>
                                        @endforeach
                                        <td class="px-4 py-3 text-sm font-semibold text-slate-700">{{ $row['total_label'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </x-ui.card>
    </div>
</div>
