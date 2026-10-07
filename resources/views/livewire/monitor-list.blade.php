<div class="mx-auto flex min-h-screen max-w-5xl flex-col gap-[1rem] p-[1rem]">
    <header class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-800 bg-slate-900 px-[1.2rem] py-[0.8rem]">
        <div class="flex items-center gap-[0.9rem]">
            <span class="flex h-[2.6rem] w-[2.6rem] items-center justify-center rounded-xl bg-brand-600 text-[1.4rem] font-bold text-white">{{ mb_substr(config('app.name'), 0, 1) }}</span>
            <h1 class="text-[1.4rem] font-semibold text-white">{{ __('Monitors') }}</h1>
        </div>
        @if($canUsePanel && Route::has('dashboard'))
            <a href="{{ route('dashboard') }}" class="rounded-lg border border-slate-700 px-[0.7rem] py-[0.3rem] text-slate-200 hover:bg-slate-800">← {{ __('Dashboard') }}</a>
        @else
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="rounded-lg border border-slate-700 px-[0.7rem] py-[0.3rem] text-slate-200 hover:bg-slate-800">{{ __('Log out') }}</button>
            </form>
        @endif
    </header>

    @if($monitors->isEmpty())
        <section class="flex flex-1 flex-col items-center justify-center rounded-2xl border border-dashed border-slate-700 p-[2rem] text-center">
            <p class="text-[1.4rem] font-semibold text-white">{{ __('No monitors yet. An admin can create them in Settings > Monitors.') }}</p>
            @if($isAdmin && Route::has('settings.monitors'))
                <a href="{{ route('settings.monitors') }}" class="mt-[1rem] rounded-lg bg-brand-600 px-[1rem] py-[0.5rem] font-medium text-white hover:bg-brand-500">{{ __('Open monitor settings') }}</a>
            @endif
        </section>
    @else
        <div class="grid gap-[1rem] sm:grid-cols-2">
            @foreach($monitors as $monitor)
                @php
                    $names = $monitor->shops->pluck('name')->sort()->values();
                    $shopsText = $names->isEmpty() ? __('All shops') : $names->take(3)->implode(', ').($names->count() > 3 ? ' +'.($names->count() - 3) : '');
                @endphp
                <a wire:key="monitor-{{ $monitor->id }}" href="{{ route('monitors.show', $monitor) }}"
                   class="flex flex-col gap-[0.5rem] rounded-2xl border border-slate-800 bg-slate-900 p-[1.2rem] hover:border-brand-500">
                    <div class="flex items-center gap-[0.6rem] text-slate-300">
                        <x-icon :name="$monitor->type->icon()" class="h-[1.4rem] w-[1.4rem]" />
                        <span class="text-[0.95rem] uppercase tracking-wide">{{ $monitor->type->label() }}</span>
                        @unless($monitor->enabled)
                            <span class="rounded bg-amber-500/20 px-[0.4rem] text-[0.8rem] font-semibold uppercase text-amber-300">{{ __('Disabled') }}</span>
                        @endunless
                    </div>
                    <p class="text-[1.5rem] font-semibold text-white">{{ $monitor->name }}</p>
                    <p class="truncate text-[0.95rem] text-slate-400">{{ $shopsText }}</p>
                    <span class="mt-[0.4rem] inline-flex w-fit items-center rounded-lg bg-brand-600 px-[0.9rem] py-[0.35rem] font-medium text-white">{{ __('Open') }}</span>
                </a>
            @endforeach
        </div>
    @endif
</div>
