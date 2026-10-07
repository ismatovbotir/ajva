<div
    wire:poll.30s
    x-data="{
        now: Date.now(),
        online: navigator.onLine,
        tz: @js($timezone),
        get stale() { return ! this.online || (this.now - Number($refs.stamp.dataset.ts) * 1000) > 100000; },
        get clock() { return new Date(this.now).toLocaleTimeString('en-GB', { timeZone: this.tz }); },
        get date() { return new Date(this.now).toLocaleDateString('en-GB', { timeZone: this.tz, day: '2-digit', month: '2-digit', year: 'numeric' }).replaceAll('/', '.'); },
        fullscreen() { document.fullscreenElement ? document.exitFullscreen() : document.documentElement.requestFullscreen(); },
    }"
    x-init="setInterval(() => now = Date.now(), 1000)"
    @online.window="online = true"
    @offline.window="online = false"
    class="flex min-h-screen flex-col gap-[0.9rem] p-[1rem] lg:h-screen lg:overflow-hidden"
>
    @if($noShops)
        <p class="rounded-2xl border border-amber-500/40 bg-amber-500/10 px-[1.2rem] py-[0.7rem] text-[1.1rem] text-amber-300">{{ $noShopsAssigned ? __('No shops are assigned to you. Ask an admin.') : __("None of this monitor's shops are available to you.") }}</p>
    @endif

    {{-- Header strip --}}
    <header class="flex flex-wrap items-center justify-between gap-x-[1.5rem] gap-y-2 rounded-2xl border bg-slate-900 px-[1.2rem] py-[0.7rem]"
            :class="stale ? 'border-red-500' : 'border-slate-800'">
        <div class="flex items-center gap-[0.9rem]">
            <span class="flex h-[2.6rem] w-[2.6rem] items-center justify-center rounded-xl bg-brand-600 text-[1.4rem] font-bold text-white">{{ mb_substr(config('app.name'), 0, 1) }}</span>
            <div class="leading-tight">
                <p class="text-[1.4rem] font-semibold text-white">{{ config('app.name') }}</p>
                <p class="text-[0.95rem] text-slate-400">{{ $monitor->name }} · {{ $monitor->type->label() }}</p>
            </div>
        </div>

        <div class="flex items-baseline gap-[1.2rem] tabular">
            <span class="text-[2.6rem] font-bold leading-none text-white" x-text="clock">{{ now()->format('H:i:s') }}</span>
            <span class="text-[1.4rem] text-slate-300" x-text="date">{{ $dateLabel }}</span>
        </div>

        <div class="flex flex-wrap items-center gap-[0.8rem] text-[1rem]">
            @if(isset($alerts) && ($alerts['below_min'] > 0 || $alerts['out_of_stock'] > 0))
                <span class="rounded-lg bg-amber-500/15 px-[0.7rem] py-[0.3rem] font-medium text-amber-300">{{ __('Below minimum') }}: <b class="tabular">{{ $alerts['below_min'] }}</b></span>
                <span class="rounded-lg bg-red-500/15 px-[0.7rem] py-[0.3rem] font-medium text-red-300">{{ __('Out of stock') }}: <b class="tabular">{{ $alerts['out_of_stock'] }}</b></span>
            @endif
            <span class="text-slate-400 tabular">{{ __('Updated') }} <span x-ref="stamp" data-ts="{{ $renderedAt }}">{{ \Illuminate\Support\Carbon::createFromTimestamp($renderedAt, $timezone)->format('H:i:s') }}</span></span>
            <span x-show="! stale" class="inline-flex items-center gap-2 rounded-lg bg-emerald-500/15 px-[0.7rem] py-[0.3rem] font-semibold text-emerald-300">
                <span class="h-[0.7rem] w-[0.7rem] rounded-full bg-emerald-400"></span>{{ __('Live') }}
            </span>
            <span x-show="stale" x-cloak class="inline-flex items-center gap-2 rounded-lg bg-red-500 px-[0.7rem] py-[0.3rem] font-bold text-white">
                ⚠ {{ __('Connection lost — data may be outdated') }}
            </span>
            <button type="button" @click="fullscreen()" class="inline-flex items-center gap-2 rounded-lg border border-slate-700 px-[0.7rem] py-[0.3rem] text-slate-200 hover:bg-slate-800">
                <x-icon name="monitor" class="h-[1.2rem] w-[1.2rem]" />{{ __('Fullscreen') }}
            </button>
            @if($public)
                {{-- Public screen: no link back into the admin panel; instead a Light/Dark switch (remembered per browser). --}}
                <button
                    type="button"
                    x-data="{
                        light: document.documentElement.getAttribute('data-monitor-theme') === 'light',
                        toggle() {
                            this.light = ! this.light;
                            if (this.light) { document.documentElement.setAttribute('data-monitor-theme', 'light'); }
                            else { document.documentElement.removeAttribute('data-monitor-theme'); }
                            try { localStorage.setItem('monitorTheme', this.light ? 'light' : 'dark'); } catch (e) {}
                        },
                    }"
                    @click="toggle()"
                    :aria-pressed="light.toString()"
                    aria-label="{{ __('Light theme') }}"
                    :title="light ? @js(__('Switch to dark theme')) : @js(__('Switch to light theme'))"
                    class="inline-flex items-center gap-2 rounded-lg border border-slate-700 px-[0.7rem] py-[0.3rem] text-slate-200 hover:bg-slate-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
                >
                    <span x-show="! light"><x-icon name="sun" class="h-[1.2rem] w-[1.2rem]" /></span>
                    <span x-show="light" x-cloak><x-icon name="moon" class="h-[1.2rem] w-[1.2rem]" /></span>
                    <span x-show="! light">{{ __('Light theme') }}</span><span x-show="light" x-cloak>{{ __('Dark theme') }}</span>
                </button>
            @elseif(Route::has('dashboard') && auth()->user()?->canUsePanel())
                @if($hasOtherMonitors)
                    <a href="{{ route('monitor') }}" class="rounded-lg border border-slate-700 px-[0.7rem] py-[0.3rem] text-slate-200 hover:bg-slate-800">{{ __('All monitors') }}</a>
                @endif
                <a href="{{ route('dashboard') }}" class="rounded-lg border border-slate-700 px-[0.7rem] py-[0.3rem] text-slate-200 hover:bg-slate-800">← {{ __('Dashboard') }}</a>
            @else
                {{-- Monitor-only accounts have no panel to go back to; they can pick another monitor or sign out. --}}
                @if($hasOtherMonitors)
                    <a href="{{ route('monitor') }}" class="rounded-lg border border-slate-700 px-[0.7rem] py-[0.3rem] text-slate-200 hover:bg-slate-800">{{ __('All monitors') }}</a>
                @endif
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="rounded-lg border border-slate-700 px-[0.7rem] py-[0.3rem] text-slate-200 hover:bg-slate-800">{{ __('Log out') }}</button>
                </form>
            @endif
        </div>
    </header>

    {{-- Type body: resources/views/livewire/monitor-types/{type}.blade.php (see App\Enums\MonitorType). --}}
    @include($typeView)
</div>
