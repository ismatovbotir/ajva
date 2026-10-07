<div class="space-y-6">
    <x-ui.page-header :title="__('Monitors')" :subtitle="__('Screens for office TVs, each with its own type and shops.')">
        <x-slot:actions>
            <x-ui.button wire:click="create">{{ __('New monitor') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
        <p class="font-semibold">{{ __('Anyone with a public link can see the sales.') }}</p>
        <ul class="mt-1 list-disc space-y-0.5 pl-5">
            <li>{{ __('Use HTTPS, and share the link only with the TV.') }}</li>
            <li>{{ __('If the link leaks, regenerate it. The old link stops working immediately.') }}</li>
            <li>{{ __('Screens that are already open with the old link go stale and show a red "connection lost" banner until the TV address is updated.') }}</li>
        </ul>
    </div>

    <div class="text-xs text-slate-500">
        <p>{{ __('The link is built from APP_URL in the server .env:') }} <code>{{ $appUrl }}</code></p>
        @if($appUrlLooksLocal)
            <p class="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">{{ __('APP_URL points to localhost, so this link will not work on the TV. Set APP_URL to the public address of this server in .env and clear the config cache.') }}</p>
        @endif
    </div>

    @if($monitors->isEmpty())
        <x-ui.empty-state :title="__('No monitors yet')" :description="__('Create a monitor, then generate its public link for the TV.')" />
    @endif

    @foreach($monitors as $monitor)
        @php
            $names = $monitor->shops->pluck('name')->sort()->values();
            $shopsText = $names->isEmpty() ? __('All shops') : $names->take(2)->implode(', ').($names->count() > 2 ? ' +'.($names->count() - 2) : '');
            $link = $monitor->publicLink();
        @endphp
        <x-ui.card wire:key="monitor-{{ $monitor->id }}">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="text-base font-semibold text-slate-900">{{ $monitor->name }}</h2>
                        <x-ui.badge :variant="$monitor->type->badgeVariant()">{{ $monitor->type->label() }}</x-ui.badge>
                        @if($monitor->enabled)
                            <x-ui.badge variant="success">{{ __('Enabled') }}</x-ui.badge>
                        @else
                            <x-ui.badge variant="warning">{{ __('Disabled') }}</x-ui.badge>
                        @endif
                        @if($monitor->show_profit)
                            <x-ui.badge variant="danger">{{ __('Public profit on') }}</x-ui.badge>
                        @endif
                    </div>
                    <p class="mt-1 truncate text-sm text-slate-500" title="{{ $names->implode(', ') }}">{{ __('Shops') }}: {{ $shopsText }}</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <x-ui.button variant="secondary" :href="route('monitors.show', $monitor)" target="_blank" rel="noopener noreferrer">{{ __('Open') }}</x-ui.button>
                    <x-ui.button variant="secondary" wire:click="edit({{ $monitor->id }})">{{ __('Edit') }}</x-ui.button>
                    <x-ui.button variant="danger" wire:click="delete({{ $monitor->id }})" wire:confirm="{{ __('Delete this monitor? Its public link stops working.') }}">{{ __('Delete') }}</x-ui.button>
                </div>
            </div>

            <div class="mt-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Public link') }}</p>
                @if($link)
                    <div class="mt-1 flex items-center gap-2" x-data="{ copied: false }">
                        <code class="min-w-0 flex-1 break-all rounded bg-sand-50 px-2 py-1 text-sm text-slate-800">{{ $link }}</code>
                        <button type="button" class="shrink-0 rounded-md border border-slate-300 bg-white px-2.5 py-1 text-xs font-medium text-slate-700"
                                @click="navigator.clipboard.writeText(@js($link)); copied = true; setTimeout(() => copied = false, 1500)"
                                x-text="copied ? @js(__('Copied')) : @js(__('Copy'))"></button>
                        {{-- noreferrer: the secret link must not leak through the Referer header --}}
                        <a href="{{ $link }}" target="_blank" rel="noopener noreferrer"
                           class="inline-flex shrink-0 items-center gap-1 rounded-md bg-brand-700 px-2.5 py-1 text-xs font-medium text-white hover:bg-brand-600">
                            <x-icon name="monitor" class="h-4 w-4" />{{ __('Open in new window') }}
                        </a>
                    </div>
                    @unless($monitor->enabled)
                        <p class="mt-1 text-xs text-amber-700">{{ __('The monitor is disabled, so the link answers with "not found".') }}</p>
                    @endunless
                @else
                    <p class="mt-1 text-sm text-slate-500">{{ __('None generated') }}</p>
                @endif
                <div class="mt-3">
                    <x-ui.button variant="secondary" wire:click="generateLink({{ $monitor->id }})"
                                 wire:confirm="{{ $link ? __('Generate a new link? The current one stops working immediately.') : __('Generate a link?') }}">
                        {{ $link ? __('Regenerate link') : __('Generate link') }}
                    </x-ui.button>
                </div>
            </div>
        </x-ui.card>
    @endforeach

    <x-ui.modal :show="$showModal" :title="$monitorId ? __('Edit monitor') : __('New monitor')">
        <form wire:submit="save" class="space-y-4">
            <x-ui.input name="name" :label="__('Name')" wire:model="name" maxlength="100" autofocus />

            <div>
                <x-ui.select name="type" :label="__('Type')" wire:model.live="type">
                    @foreach($types as $typeOption)
                        <option value="{{ $typeOption->value }}">{{ $typeOption->label() }}</option>
                    @endforeach
                </x-ui.select>
                <ul class="mt-2 space-y-0.5 text-xs text-slate-500">
                    @foreach($types as $typeOption)
                        <li><span class="font-medium text-slate-700">{{ $typeOption->label() }}</span> — {{ $typeOption->description() }}</li>
                    @endforeach
                </ul>
            </div>

            <div>
                <div class="flex items-center justify-between">
                    <x-ui.label>{{ __('Shops') }}</x-ui.label>
                    <div class="flex gap-3 text-xs">
                        <button type="button" wire:click="selectAllShops" class="font-medium text-brand-700 hover:underline">{{ __('Select all') }}</button>
                        <button type="button" wire:click="clearShops" class="font-medium text-slate-500 hover:underline">{{ __('Clear') }}</button>
                    </div>
                </div>
                <div class="mt-1 max-h-56 divide-y divide-slate-100 overflow-y-auto rounded-lg border border-slate-300">
                    @forelse($shops as $shopOption)
                        <label wire:key="shop-opt-{{ $shopOption->id }}" class="flex cursor-pointer items-center gap-3 px-3 py-2.5 text-sm text-slate-800 hover:bg-sand-50">
                            <input type="checkbox" value="{{ $shopOption->id }}" wire:model="shopIds" class="h-4 w-4 rounded border-slate-300 text-brand-700 focus:ring-brand-600" />
                            <span class="min-w-0 truncate">{{ $shopOption->name }}</span>
                        </label>
                    @empty
                        <p class="px-3 py-2.5 text-sm text-slate-500">{{ __('No shops yet') }}</p>
                    @endforelse
                </div>
                <p class="mt-1 text-xs text-slate-500">{{ __('Leave empty to show all shops.') }}</p>
                @if($type === \App\Enums\MonitorType::Warehouse->value)
                    <p class="mt-1 text-xs text-slate-500">
                        {{ __('The main warehouse is excluded automatically.') }}
                        {{ $warehouse ? __('Main warehouse: :name', ['name' => $warehouse->name]) : __('The main warehouse is not set yet (Settings > Warehouse).') }}
                    </p>
                @endif
                @error('shopIds')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                @error('shopIds.*')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>

            <div>
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-slate-800">{{ __('Enabled') }}</p>
                        <p class="text-xs text-slate-500">{{ __('When disabled, the public link answers with "not found" and only admins can open it.') }}</p>
                    </div>
                    <button type="button" role="switch" aria-checked="{{ $enabled ? 'true' : 'false' }}" wire:click="$toggle('enabled')"
                            class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition {{ $enabled ? 'bg-brand-700' : 'bg-slate-300' }}">
                        <span class="sr-only">{{ __('Enabled') }}</span>
                        <span class="inline-block h-5 w-5 transform rounded-full bg-white shadow transition {{ $enabled ? 'translate-x-5' : 'translate-x-0.5' }}"></span>
                    </button>
                </div>
            </div>

            <div>
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-slate-800">{{ __('Show profit on the public screen') }}</p>
                        <p class="text-xs text-slate-500">{{ __('Off by default: profit, margin and cost figures are then not sent to the public screen at all.') }}</p>
                    </div>
                    <button type="button" role="switch" aria-checked="{{ $showProfit ? 'true' : 'false' }}" wire:click="$toggle('showProfit')"
                            class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition {{ $showProfit ? 'bg-brand-700' : 'bg-slate-300' }}">
                        <span class="sr-only">{{ __('Show profit on the public screen') }}</span>
                        <span class="inline-block h-5 w-5 transform rounded-full bg-white shadow transition {{ $showProfit ? 'translate-x-5' : 'translate-x-0.5' }}"></span>
                    </button>
                </div>
                @if($showProfit)
                    <p class="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">{{ __('Anyone with the public link will see profit and margins.') }}</p>
                @endif
            </div>

            <div class="flex justify-end gap-2">
                <x-ui.button type="button" variant="secondary" wire:click="closeModal">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit">{{ __('Save') }}</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
