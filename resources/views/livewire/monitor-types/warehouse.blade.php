{{-- Warehouse monitor body, rendered inside livewire/monitor-screen.blade.php (the shared shell).
     Data: $rec = App\Services\OrderRecommendations::build() (trimmed: top rows per shop, capped pick list),
     $warehouseName. Dark utility classes only, so the public light theme remaps them. --}}
@php
    $qty = fn ($n) => rtrim(rtrim(number_format((float) $n, 1, '.', ' '), '0'), '.') ?: '0';
    $kpi = $rec['kpi'];
    $configured = $rec['warehouse_configured'];
    $panel = 'flex min-h-0 flex-col rounded-2xl border border-slate-800 bg-slate-900 p-[1rem]';
    $panelTitle = 'text-[1.15rem] font-semibold uppercase tracking-wide text-slate-300';
    $badge = [
        'out' => ['bg-red-500 text-white', __('Out of stock')],
        'critical' => ['bg-red-500/20 text-red-300', __('< 1 day')],
        'soon' => ['bg-amber-500/20 text-amber-300', __('< 3 days')],
        'low' => ['bg-slate-700 text-slate-200', __('Low')],
    ];
    $needy = array_values(array_filter($rec['shops'], fn ($s) => $s['count'] > 0));
    $quiet = array_values(array_filter($rec['shops'], fn ($s) => $s['count'] === 0));
    $cardRows = count($needy) <= 4 ? 6 : 4;
    $shopPages = array_chunk($needy, 6);
    $pickPages = array_chunk($rec['pick'], 10);
    $nothing = $kpi['lines'] === 0;
    $tiles = [
        ['label' => __('Shops needing a delivery'), 'value' => $kpi['shops'].' / '.count($rec['shops']), 'tone' => 'text-white'],
        ['label' => __('Items to pick'), 'value' => (string) $kpi['items'], 'tone' => 'text-white', 'hint' => $kpi['lines'].' '.__('lines')],
        ['label' => __('Out of stock'), 'value' => (string) $kpi['out'], 'tone' => $kpi['out'] > 0 ? 'text-red-400' : 'text-white', 'hint' => __('lines')],
        ['label' => __('Critical (< 1 day)'), 'value' => (string) $kpi['critical'], 'tone' => $kpi['critical'] > 0 ? 'text-red-400' : 'text-white', 'hint' => __('lines')],
        ['label' => __('Under 3 days'), 'value' => (string) $kpi['soon'], 'tone' => $kpi['soon'] > 0 ? 'text-amber-300' : 'text-white', 'hint' => __('lines')],
        $configured
            ? ['label' => __('Shortages vs warehouse'), 'value' => (string) $kpi['short_items'], 'tone' => $kpi['short_items'] > 0 ? 'text-red-400' : 'text-emerald-400', 'hint' => $kpi['short_items'] > 0 ? __('items the warehouse cannot fully cover') : __('the warehouse covers everything')]
            : ['label' => __('Shortages vs warehouse'), 'value' => '—', 'tone' => 'text-slate-500', 'hint' => __('main warehouse not set')],
    ];
@endphp

    @unless($configured)
        <p class="rounded-2xl border border-amber-500/40 bg-amber-500/10 px-[1.2rem] py-[0.6rem] text-[1.05rem] text-amber-300">⚠ {{ __('The main warehouse is not set. An admin can choose it in Settings > Warehouse.') }} {{ __('Needs are shown without checking the warehouse stock.') }}</p>
    @endunless

    {{-- KPI strip --}}
    <section class="grid grid-cols-2 gap-[0.9rem] lg:grid-cols-6" aria-label="{{ __('Key figures') }}">
        @foreach($tiles as $tile)
            <div class="{{ $panel }} justify-between gap-1" data-kpi="{{ $loop->index }}">
                <p class="{{ $panelTitle }}">{{ $tile['label'] }}</p>
                <p class="tabular truncate text-[3rem] font-bold leading-none {{ $tile['tone'] }}">{{ $tile['value'] }}</p>
                <p class="text-[0.9rem] leading-snug text-slate-400">{{ $tile['hint'] ?? '' }}&nbsp;</p>
            </div>
        @endforeach
    </section>

    @if($nothing)
        <section class="flex min-h-0 flex-1 flex-col items-center justify-center rounded-2xl border border-slate-800 bg-slate-900 p-[2rem] text-center" data-state="nothing">
            <x-icon name="box" class="h-[4rem] w-[4rem] text-emerald-400" />
            @if($noShops)
                <p class="mt-[1rem] text-[2.2rem] font-semibold text-white">{{ __('No shops to show') }}</p>
            @else
                <p class="mt-[1rem] text-[2.2rem] font-semibold text-white">{{ __('Nothing to deliver') }}</p>
                <p class="mt-[0.4rem] text-[1.3rem] text-slate-400">{{ __('All shops are above their minimum with enough days of cover.') }}</p>
            @endif
        </section>
    @else
        <section class="grid min-h-0 flex-1 grid-cols-1 gap-[0.9rem] lg:grid-cols-12">
            {{-- A card per shop (most urgent first), paged when there are more than fit --}}
            <div class="{{ $panel }} lg:col-span-7"
                 data-panel="shops" data-pages="{{ max(count($shopPages), 1) }}"
                 x-data="{ page: 0, get cur() { return this.page % (Number(this.$root.dataset.pages) || 1); } }"
                 x-init="setInterval(() => page++, 20000)">
                <div class="flex items-center justify-between gap-2">
                    <h2 class="{{ $panelTitle }}">{{ __('Deliveries by shop') }}</h2>
                    @if(count($shopPages) > 1)
                        <div class="flex items-center gap-[0.4rem] text-[0.9rem] text-slate-400" aria-label="{{ __('Page') }}">
                            @foreach($shopPages as $i => $unused)
                                <span class="h-[0.7rem] w-[0.7rem] rounded-full" :class="cur === {{ $i }} ? 'bg-white' : 'bg-slate-700'"></span>
                            @endforeach
                            <span class="tabular ml-1" x-text="(cur + 1) + '/{{ count($shopPages) }}'">1/{{ count($shopPages) }}</span>
                        </div>
                    @endif
                </div>
                @foreach($shopPages as $i => $page)
                    <div class="mt-2 grid min-h-0 flex-1 grid-cols-2 gap-[0.7rem] {{ count($needy) <= 4 ? 'grid-rows-2' : 'grid-rows-3' }}" data-shop-page="{{ $i }}" @if($i > 0) x-cloak @endif x-show="cur === {{ $i }}">
                        @foreach($page as $shop)
                            <article class="flex min-h-0 flex-col overflow-hidden rounded-xl border {{ $shop['counts']['out'] > 0 ? 'border-red-500/60' : 'border-slate-800' }} bg-slate-800/30 p-[0.6rem]" data-shop="{{ $shop['id'] }}">
                                <div class="flex items-baseline justify-between gap-2">
                                    <h3 class="min-w-0 truncate text-[1.25rem] font-bold text-white">{{ $shop['name'] }}</h3>
                                    <span class="tabular shrink-0 text-[1rem] text-slate-300">{{ $shop['count'] }} {{ __('lines') }}</span>
                                </div>
                                <p class="tabular mt-[0.2rem] flex flex-wrap gap-x-[0.7rem] text-[0.85rem] font-semibold">
                                    @foreach(['out' => 'text-red-400', 'critical' => 'text-red-300', 'soon' => 'text-amber-300', 'low' => 'text-slate-300'] as $level => $tone)
                                        @if($shop['counts'][$level] > 0)<span class="{{ $tone }}">{{ $badge[$level][1] }}: {{ $shop['counts'][$level] }}</span>@endif
                                    @endforeach
                                </p>
                                <ul class="tabular mt-[0.3rem] min-h-0 flex-1 divide-y divide-slate-800 overflow-hidden text-[0.98rem]">
                                    @foreach(array_slice($shop['rows'], 0, $cardRows) as $row)
                                        <li class="flex items-center gap-[0.5rem] py-[0.15rem]">
                                            <span class="w-[0.55rem] shrink-0 text-center text-[0.7rem] {{ $row['urgency'] === 'out' ? 'text-red-400' : ($row['urgency'] === 'critical' ? 'text-red-300' : ($row['urgency'] === 'soon' ? 'text-amber-300' : 'text-slate-500')) }}" title="{{ $badge[$row['urgency']][1] }}">●</span>
                                            <span class="min-w-0 flex-1 truncate text-slate-100">{{ $row['item'] }}</span>
                                            <span class="shrink-0 text-[0.85rem] {{ $row['urgency'] === 'out' ? 'font-bold text-red-300' : 'text-slate-400' }}">{{ $row['urgency'] === 'out' ? __('Out of stock') : ($row['cover'] !== null ? $qty($row['cover']).' '.__('d') : __('no sales data')) }}</span>
                                            <span class="w-[4.2rem] shrink-0 text-right font-bold text-white">+{{ $qty($row['send']) }}@if($row['short'] > 0)<span class="text-red-400" title="{{ __('Warehouse shortage') }}"> ⚠</span>@endif</span>
                                        </li>
                                    @endforeach
                                </ul>
                                @if($shop['count'] > $cardRows)
                                    <p class="tabular text-right text-[0.8rem] text-slate-500">+{{ $shop['count'] - $cardRows }} {{ __('more') }}</p>
                                @endif
                            </article>
                        @endforeach
                    </div>
                @endforeach
                @if(! empty($quiet))
                    <p class="mt-[0.5rem] truncate text-[0.9rem] text-slate-400" data-quiet>✓ {{ __('Nothing needed') }}: {{ collect($quiet)->pluck('name')->implode(', ') }}</p>
                @endif
            </div>

            {{-- Consolidated pick list: what the warehouse team prepares, all shops together --}}
            <div class="{{ $panel }} lg:col-span-5"
                 data-panel="pick" data-pages="{{ max(count($pickPages), 1) }}"
                 x-data="{ page: 0, get cur() { return this.page % (Number(this.$root.dataset.pages) || 1); } }"
                 x-init="setInterval(() => page++, 20000)">
                <div class="flex items-center justify-between gap-2">
                    <h2 class="{{ $panelTitle }}">{{ __('Pick list') }}@if($configured && $warehouseName)<span class="ml-2 text-[0.85rem] font-normal normal-case text-slate-500">{{ $warehouseName }}</span>@endif</h2>
                    @if(count($pickPages) > 1)
                        <div class="flex items-center gap-[0.4rem] text-[0.9rem] text-slate-400" aria-label="{{ __('Page') }}">
                            @foreach($pickPages as $i => $unused)
                                <span class="h-[0.7rem] w-[0.7rem] rounded-full" :class="cur === {{ $i }} ? 'bg-white' : 'bg-slate-700'"></span>
                            @endforeach
                            <span class="tabular ml-1" x-text="(cur + 1) + '/{{ count($pickPages) }}'">1/{{ count($pickPages) }}</span>
                        </div>
                    @endif
                </div>
                @foreach($pickPages as $i => $page)
                    <ul class="tabular mt-2 min-h-0 flex-1 divide-y divide-slate-800 overflow-hidden" data-pick-page="{{ $i }}" @if($i > 0) x-cloak @endif x-show="cur === {{ $i }}">
                        @foreach($page as $item)
                            <li class="py-[0.3rem]">
                                <div class="flex items-center gap-[0.6rem] text-[1.05rem]">
                                    <span class="w-[5.6rem] shrink-0 rounded px-[0.3rem] text-center text-[0.75rem] font-bold uppercase {{ $badge[$item['urgency']][0] }}">{{ $badge[$item['urgency']][1] }}</span>
                                    <span class="min-w-0 flex-1 truncate font-semibold text-white">{{ $item['item'] }}</span>
                                    <span class="shrink-0 text-right text-[1.25rem] font-bold {{ $item['send'] > 0 ? 'text-white' : 'text-red-400' }}">{{ $qty($item['send']) }}</span>
                                </div>
                                <div class="mt-[0.1rem] flex items-baseline justify-between gap-2 pl-[6.2rem] text-[0.85rem] text-slate-400">
                                    <span class="min-w-0 truncate">{{ collect($item['shops'])->map(fn ($s) => $s['shop'].' '.$qty($s['send']))->implode(' · ') }}</span>
                                    @if($item['short'] > 0)
                                        <span class="shrink-0 font-semibold text-red-300">⚠ {{ __('short') }} {{ $qty($item['short']) }} · {{ __('warehouse has') }} {{ $qty($item['available'] ?? 0) }}</span>
                                    @elseif($configured)
                                        <span class="shrink-0">{{ __('warehouse has') }} {{ $qty($item['available'] ?? 0) }}</span>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endforeach
                @if($rec['pick_more'] > 0)
                    <p class="tabular text-right text-[0.85rem] text-slate-500" data-pick-more>+{{ $rec['pick_more'] }} {{ __('more items') }}</p>
                @endif
            </div>
        </section>
    @endif
