<div class="space-y-6">
    <x-ui.page-header :title="__('Public monitor')" :subtitle="__('A link for an office TV that cannot log in.')" />

    <div class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
        <p class="font-semibold">{{ __('Anyone with the link can see the sales.') }}</p>
        <ul class="mt-1 list-disc space-y-0.5 pl-5">
            <li>{{ __('Use HTTPS, and share the link only with the TV.') }}</li>
            <li>{{ __('If the link leaks, regenerate it. The old link stops working immediately.') }}</li>
            <li>{{ __('Screens that are already open with the old link go stale and show a red "connection lost" banner until the TV address is updated.') }}</li>
        </ul>
    </div>

    <x-ui.card>
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <div class="flex items-center gap-2">
                    <h2 class="text-base font-semibold text-slate-900">{{ __('Public screen') }}</h2>
                    @if($enabled && $hasToken)
                        <x-ui.badge variant="success">{{ __('Ready') }}</x-ui.badge>
                    @elseif(! $enabled)
                        <x-ui.badge variant="warning">{{ __('Disabled') }}</x-ui.badge>
                    @else
                        <x-ui.badge variant="danger">{{ __('No link yet') }}</x-ui.badge>
                    @endif
                </div>
                <p class="mt-1 text-sm text-slate-500">{{ __('When disabled, the link answers with "not found".') }}</p>
            </div>
            <button type="button" role="switch" aria-checked="{{ $enabled ? 'true' : 'false' }}" wire:click="toggleEnabled"
                    class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition {{ $enabled ? 'bg-brand-700' : 'bg-slate-300' }}">
                <span class="sr-only">{{ __('Enable public screen') }}</span>
                <span class="inline-block h-5 w-5 transform rounded-full bg-white shadow transition {{ $enabled ? 'translate-x-5' : 'translate-x-0.5' }}"></span>
            </button>
        </div>

        <div class="mt-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Link') }}</p>
            <p class="mb-2 text-xs text-slate-500">{{ __('The link is built from APP_URL in the server .env:') }} <code>{{ $appUrl }}</code></p>
            @if($appUrlLooksLocal)
                <p class="mb-2 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">{{ __('APP_URL points to localhost, so this link will not work on the TV. Set APP_URL to the public address of this server in .env and clear the config cache.') }}</p>
            @endif
            @if($link)
                <div class="mt-1 flex items-center gap-2" x-data="{ copied: false }">
                    <code class="min-w-0 flex-1 break-all rounded bg-sand-50 px-2 py-1 text-sm text-slate-800">{{ $link }}</code>
                    <button type="button" class="shrink-0 rounded-md border border-slate-300 bg-white px-2.5 py-1 text-xs font-medium text-slate-700"
                            @click="navigator.clipboard.writeText(@js($link)); copied = true; setTimeout(() => copied = false, 1500)"
                            x-text="copied ? @js(__('Copied')) : @js(__('Copy'))"></button>
                </div>
                <p class="mt-1 text-xs text-slate-500">{{ __('Generated') }} {{ $tokenCreatedAt }}</p>
            @else
                <p class="mt-1 text-sm text-slate-500">{{ __('None generated') }}</p>
            @endif
        </div>

        <div class="mt-4">
            <x-ui.button type="button" wire:click="generateToken"
                         wire:confirm="{{ $hasToken ? __('Generate a new link? The current one stops working immediately.') : __('Generate a link?') }}">
                {{ $hasToken ? __('Regenerate link') : __('Generate link') }}
            </x-ui.button>
        </div>
    </x-ui.card>

    <x-ui.card>
        <div class="flex items-start justify-between gap-4">
            <div class="min-w-0">
                <h2 class="text-base font-semibold text-slate-900">{{ __('Show profit on the public screen') }}</h2>
                <p class="mt-1 text-sm text-slate-500">{{ __('Off by default: profit, margin and cost figures are then not sent to the public screen at all.') }}</p>
            </div>
            <button type="button" role="switch" aria-checked="{{ $showProfit ? 'true' : 'false' }}" wire:click="toggleProfit"
                    class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition {{ $showProfit ? 'bg-brand-700' : 'bg-slate-300' }}">
                <span class="sr-only">{{ __('Show profit on the public screen') }}</span>
                <span class="inline-block h-5 w-5 transform rounded-full bg-white shadow transition {{ $showProfit ? 'translate-x-5' : 'translate-x-0.5' }}"></span>
            </button>
        </div>
    </x-ui.card>
</div>
