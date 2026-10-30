<div>
    <x-ui.page-header :title="__('Telegram messages')" :subtitle="__('Messages received by the bot and sent from the panel.')" />

    <div class="mb-4 flex flex-col gap-2 sm:flex-row">
        <div class="max-w-sm flex-1">
            <x-ui.input name="search" :placeholder="__('Search...')" wire:model.live.debounce.300ms="search" />
        </div>
        <x-ui.select name="direction" wire:model.live="direction">
            <option value="">{{ __('All messages') }}</option>
            <option value="in">{{ __('Received') }}</option>
            <option value="out">{{ __('Sent') }}</option>
        </x-ui.select>
    </div>

    @if($messages->isEmpty())
        <x-ui.empty-state :title="__('No messages yet')" :description="__('Messages appear here after someone writes to the bot.')" />
    @else
        {{-- Mobile card list --}}
        <div class="space-y-3 md:hidden">
            @foreach($messages as $m)
                <x-ui.card>
                    <div class="flex items-center justify-between gap-2">
                        <p class="truncate font-medium text-slate-900">{{ $m->telegram?->displayName() ?? '#'.$m->chat_id }}</p>
                        <x-ui.badge :variant="$m->direction === 'out' ? 'success' : 'info'">{{ $m->direction === 'out' ? __('Sent') : __('Received') }}</x-ui.badge>
                    </div>
                    <p class="mt-1 whitespace-pre-line break-words text-sm text-slate-700">{{ $m->text ?? '['.$m->type.']' }}</p>
                    <p class="mt-1 text-xs text-slate-400">{{ $m->type }} · chat {{ $m->chat_id }}@if($m->from_id) · from {{ $m->from_id }}@endif · {{ $m->sent_at?->format('d.m.Y H:i') }}</p>
                </x-ui.card>
            @endforeach
        </div>

        {{-- Desktop table --}}
        <x-ui.card padding="p-0" class="hidden md:block">
            <table class="min-w-full divide-y divide-slate-200">
                <thead class="bg-sand-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Time') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Direction') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Person') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Chat id') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('From id') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Type') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Message') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($messages as $m)
                        <tr>
                            <td class="whitespace-nowrap px-4 py-3 text-sm text-slate-500">{{ $m->sent_at?->format('d.m.Y H:i') }}</td>
                            <td class="px-4 py-3 text-sm"><x-ui.badge :variant="$m->direction === 'out' ? 'success' : 'info'">{{ $m->direction === 'out' ? __('Sent') : __('Received') }}</x-ui.badge>@if($m->sender)<span class="ml-1 text-xs text-slate-400">{{ $m->sender->name }}</span>@endif</td>
                            <td class="px-4 py-3 text-sm text-slate-900">{{ $m->telegram?->displayName() ?? '—' }}</td>
                            <td class="px-4 py-3 text-sm text-slate-500">{{ $m->chat_id }}</td>
                            <td class="px-4 py-3 text-sm text-slate-500">{{ $m->from_id ?? '—' }}</td>
                            <td class="px-4 py-3 text-sm text-slate-500">{{ $m->type }}</td>
                            <td class="max-w-md whitespace-pre-line break-words px-4 py-3 text-sm text-slate-700">{{ $m->text ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-ui.card>

        <div class="mt-4">{{ $messages->links() }}</div>
    @endif
</div>
