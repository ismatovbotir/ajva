<div>
    <x-ui.page-header :title="__('Shops')" :subtitle="__('The shops known to the system, synced from 1C.')" />

    @if($shops->isEmpty())
        <x-ui.empty-state :title="__('No shops yet')" :description="__('Shops are created automatically from 1C sync.')" />
    @else
        {{-- Mobile card list --}}
        <div class="space-y-3 md:hidden">
            @foreach($shops as $shop)
                <x-ui.card>
                    <p class="font-medium text-slate-900">{{ $shop->name }}</p>
                    <p class="text-sm text-slate-500">#{{ $shop->id }}</p>
                </x-ui.card>
            @endforeach
        </div>

        {{-- Desktop table --}}
        <x-ui.card padding="p-0" class="hidden md:block">
            <table class="min-w-full divide-y divide-slate-200">
                <thead class="bg-sand-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('ID') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Name') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($shops as $shop)
                        <tr>
                            <td class="px-4 py-3 text-sm text-slate-500">#{{ $shop->id }}</td>
                            <td class="px-4 py-3 text-sm font-medium text-slate-900">{{ $shop->name }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-ui.card>

        <div class="mt-4">{{ $shops->links() }}</div>
    @endif
</div>
