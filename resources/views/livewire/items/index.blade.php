<div>
    <x-ui.page-header :title="__('Items')" :subtitle="__('The product catalog, synced from 1C.')" />

    <div class="mb-4 flex flex-col gap-3 sm:flex-row">
        <div class="flex-1">
            <x-ui.input name="search" :label="__('Search')" wire:model.live.debounce.300ms="search" placeholder="{{ __('Search by name or mark') }}" />
        </div>
        <div class="sm:w-64">
            <x-ui.select name="groupFilter" :label="__('Group')" wire:model.live="groupFilter">
                <option value="">{{ __('All groups') }}</option>
                @foreach($groups as $group)
                    <option value="{{ $group->id }}">{{ $group->name }}</option>
                @endforeach
            </x-ui.select>
        </div>
    </div>

    @if($items->isEmpty())
        <x-ui.empty-state :title="__('No items found')" :description="__('Try adjusting your search.')" />
    @else
        {{-- Mobile card list --}}
        <div class="max-h-[70vh] space-y-3 overflow-y-auto md:hidden">
            @foreach($items as $item)
                <x-ui.card>
                    <a href="{{ route('items.show', $item) }}" class="block">
                        <p class="truncate font-medium text-slate-900">{{ $item->name }}</p>
                        <p class="text-sm text-slate-500">{{ $item->mark ?? '—' }} &middot; {{ $item->group?->name ?? __('No group') }}</p>
                    </a>
                </x-ui.card>
            @endforeach
        </div>

        {{-- Desktop table --}}
        <x-ui.card padding="p-0" class="hidden md:block">
            <div class="max-h-[70vh] overflow-auto">
            <table class="min-w-full divide-y divide-slate-200">
                <thead class="sticky top-0 z-10 bg-sand-50 shadow-[0_1px_0_0_var(--color-slate-200)]">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Name') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Mark') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Group') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Category') }}</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($items as $item)
                        <tr>
                            <td class="px-4 py-3 text-sm font-medium text-slate-900">
                                <a href="{{ route('items.show', $item) }}" class="hover:text-accent hover:underline">{{ $item->name }}</a>
                            </td>
                            <td class="px-4 py-3 text-sm text-slate-500">{{ $item->mark ?? '—' }}</td>
                            <td class="px-4 py-3 text-sm text-slate-500">{{ $item->group?->name ?? '—' }}</td>
                            <td class="px-4 py-3 text-sm text-slate-500">{{ $item->category?->name ?? '—' }}</td>
                            <td class="px-4 py-3 text-right text-sm">
                                <x-ui.button variant="secondary" :href="route('items.show', $item)">{{ __('View') }}</x-ui.button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        </x-ui.card>

        <div class="mt-4">{{ $items->links() }}</div>
    @endif
</div>
