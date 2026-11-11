<div>
    <x-ui.page-header :title="$shop->name" :subtitle="'#'.$shop->id">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('shops.index')">{{ __('Back to shops') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if(session('status'))
        <p class="mb-3 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{{ session('status') }}</p>
    @endif

    <x-ui.card class="mb-6">
        <div class="flex items-start justify-between gap-3">
            <h2 class="text-lg font-semibold text-slate-900">{{ __('Shop details') }}</h2>
            @if(auth()->user()?->isAdmin() && ! $editing)
                <x-ui.button variant="secondary" wire:click="edit">{{ __('Edit') }}</x-ui.button>
            @endif
        </div>

        @if($editing)
            <form wire:submit="save" class="mt-4 grid gap-4 md:grid-cols-2">
                <div>
                    <x-ui.label for="areaM2">{{ __('Area, m²') }}</x-ui.label>
                    <input id="areaM2" type="text" inputmode="decimal" wire:model="areaM2" placeholder="120"
                           class="block h-11 w-full rounded-lg border-slate-300 bg-white px-3.5 text-[15px] shadow-sm placeholder:text-slate-400 focus:border-primary-500 focus:ring-primary-500">
                    @error('areaM2') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <x-ui.label for="mapUrl">{{ __('Yandex Maps link') }}</x-ui.label>
                    <input id="mapUrl" type="url" wire:model="mapUrl" placeholder="https://yandex.com/maps/..."
                           class="block h-11 w-full rounded-lg border-slate-300 bg-white px-3.5 text-[15px] shadow-sm placeholder:text-slate-400 focus:border-primary-500 focus:ring-primary-500">
                    @error('mapUrl') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
                <label class="flex items-center gap-2 text-sm text-slate-800 md:col-span-2">
                    <input type="checkbox" wire:model="isDisabled" class="h-5 w-5 rounded border-slate-300 text-primary-600 focus:ring-primary-500">
                    {{ __('Disable this shop (hide it from the shop selectors of reports)') }}
                </label>
                <p class="text-sm text-slate-500 md:col-span-2">{{ __('These two fields are kept only here. 1C does not send them and never overwrites them.') }}</p>
                <div class="flex justify-end gap-2 md:col-span-2">
                    <x-ui.button type="button" variant="secondary" wire:click="$set('editing', false)">{{ __('Cancel') }}</x-ui.button>
                    <x-ui.button type="submit">{{ __('Save') }}</x-ui.button>
                </div>
            </form>
        @else
            @if($shop->is_disabled)
                <p class="mt-3 inline-block rounded bg-slate-100 px-2 py-1 text-xs font-medium text-slate-700">{{ __('Disabled') }}</p>
            @endif
            <dl class="mt-4 grid gap-4 md:grid-cols-2">
                <div>
                    <dt class="text-xs font-medium text-slate-500">{{ __('Area, m²') }}</dt>
                    <dd class="mt-1 text-sm text-slate-900">
                        {{ $shop->area_m2 !== null ? rtrim(rtrim(number_format((float) $shop->area_m2, 2, '.', ' '), '0'), '.').' m²' : '—' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-medium text-slate-500">{{ __('Yandex Maps link') }}</dt>
                    <dd class="mt-1 text-sm">
                        @if($shop->map_url)
                            <a href="{{ $shop->map_url }}" target="_blank" rel="noopener noreferrer" class="font-medium text-primary-700 hover:underline">{{ __('Open on Yandex Maps') }}</a>
                        @else
                            <span class="text-slate-900">—</span>
                        @endif
                    </dd>
                </div>
            </dl>
        @endif
    </x-ui.card>

    <h2 class="mb-3 text-lg font-semibold text-slate-900">{{ __('Items in this shop') }}</h2>

    @if($stocks->isEmpty())
        <x-ui.empty-state :title="__('No stock at this shop yet')" />
    @else
        <x-ui.card padding="p-0">
            <table class="min-w-full divide-y divide-slate-200">
                <thead class="bg-sand-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Item') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Mark') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Qty') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($stocks as $stock)
                        <tr class="cursor-pointer hover:bg-sand-50" onclick="window.location='{{ route('items.show', $stock->item) }}'">
                            <td class="px-4 py-3 text-sm font-medium text-slate-900">
                                <a href="{{ route('items.show', $stock->item) }}" class="hover:text-brand-700 hover:underline">{{ $stock->item->name }}</a>
                            </td>
                            <td class="px-4 py-3 text-sm text-slate-500">{{ $stock->item->mark ?? '—' }}</td>
                            <td class="px-4 py-3 text-sm text-slate-500">@if($stock->reported ?? true){{ $stock->qty }}@else<span class="rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600" title="{{ __('Last reported') }} {{ $stock->stock_date?->format('Y-m-d') }}" data-not-reported>{{ __('Not reported') }}</span>@endif</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-ui.card>
    @endif
</div>
