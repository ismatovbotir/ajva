<div>
    <x-ui.page-header :title="__('Telegram users')" :subtitle="__('People who wrote to the bot. Collected every minute.')" />

    <div class="mb-4 max-w-sm">
        <x-ui.input name="search" :placeholder="__('Search...')" wire:model.live.debounce.300ms="search" />
    </div>

    @if($users->isEmpty())
        <x-ui.empty-state :title="__('No Telegram users yet')" :description="__('A person appears here after writing to the bot.')" />
    @else
        {{-- Mobile card list --}}
        <div class="space-y-3 md:hidden">
            @foreach($users as $tu)
                <x-ui.card>
                    <div class="flex items-center justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate font-medium text-slate-900">{{ $tu->displayName() }}</p>
                            <p class="text-sm text-slate-500">{{ $tu->username ? '@'.$tu->username.' · ' : '' }}{{ $tu->tg_id }}</p>
                            @if(isset($panelUsers[(string) $tu->tg_id]))
                                <x-ui.badge variant="info" class="mt-1">{{ $panelUsers[(string) $tu->tg_id] }}</x-ui.badge>
                            @endif
                        </div>
                        <x-ui.button variant="secondary" wire:click="compose({{ $tu->id }})">{{ __('Send message') }}</x-ui.button>
                    </div>
                </x-ui.card>
            @endforeach
        </div>

        {{-- Desktop table --}}
        <x-ui.card padding="p-0" class="hidden md:block">
            <table class="min-w-full divide-y divide-slate-200">
                <thead class="bg-sand-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Name') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Username') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Telegram id') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('User') }}</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Messages') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Last message') }}</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($users as $tu)
                        <tr>
                            <td class="px-4 py-3 text-sm font-medium text-slate-900">{{ $tu->displayName() }}</td>
                            <td class="px-4 py-3 text-sm text-slate-500">{{ $tu->username ? '@'.$tu->username : '—' }}</td>
                            <td class="px-4 py-3 text-sm text-slate-500">{{ $tu->tg_id }}</td>
                            <td class="px-4 py-3 text-sm text-slate-500">{{ $panelUsers[(string) $tu->tg_id] ?? '—' }}</td>
                            <td class="px-4 py-3 text-right text-sm text-slate-500">{{ $tu->messages_count }}</td>
                            <td class="px-4 py-3 text-sm text-slate-500">{{ $tu->last_message_at?->format('d.m.Y H:i') ?? '—' }}</td>
                            <td class="px-4 py-3 text-right text-sm">
                                <x-ui.button variant="secondary" wire:click="compose({{ $tu->id }})">{{ __('Send message') }}</x-ui.button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-ui.card>

        <div class="mt-4">{{ $users->links() }}</div>
    @endif

    <x-ui.modal :show="$showModal" :title="__('Send message')">
        <form wire:submit="send" class="space-y-4">
            @if($target)
                <p class="text-sm text-slate-600">{{ __('To') }}: <span class="font-medium text-slate-900">{{ $target->displayName() }}</span> <span class="text-slate-400">({{ $target->tg_id }})</span></p>
            @endif
            <x-ui.textarea name="text" :label="__('Message')" rows="5" wire:model="text" maxlength="4000" autofocus />
            @if($result)
                <p class="rounded-lg px-3 py-2 text-sm {{ $failed ? 'bg-red-50 text-red-700' : 'bg-brand-50 text-brand-800' }}">{{ $result }}</p>
            @endif
            <div class="flex justify-end gap-2">
                <x-ui.button type="button" variant="secondary" wire:click="closeModal">{{ __('Close') }}</x-ui.button>
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="send">{{ __('Send') }}</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
