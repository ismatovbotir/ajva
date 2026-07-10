<div>
    <x-ui.page-header :title="__('Groups')" :subtitle="__('Product groups, synced from 1C.')" />

    @if($groups->isEmpty())
        <x-ui.empty-state :title="__('No groups yet')" :description="__('Groups are created automatically from 1C sync.')" />
    @else
        {{-- Mobile card list --}}
        <div class="space-y-3 md:hidden">
            @foreach($groups as $group)
                <x-ui.card>
                    <p class="font-medium text-slate-900">{{ $group->name }}</p>
                    <p class="text-sm text-slate-500">#{{ $group->id }}</p>
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
                    @foreach($groups as $group)
                        <tr>
                            <td class="px-4 py-3 text-sm text-slate-500">#{{ $group->id }}</td>
                            <td class="px-4 py-3 text-sm font-medium text-slate-900">{{ $group->name }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-ui.card>

        <div class="mt-4">{{ $groups->links() }}</div>
    @endif
</div>
