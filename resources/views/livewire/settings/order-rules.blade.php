@php
    $statusVariant = ['created' => 'success', 'skipped_empty' => 'default', 'skipped_draft' => 'default', 'waiting_stock' => 'warning', 'skipped_no_stock' => 'warning', 'error' => 'danger', 'running' => 'info'];
    $statusLabel = [
        'created' => __('Draft created'),
        'skipped_empty' => __('Nothing to order'),
        'skipped_draft' => __('Draft already exists'),
        'waiting_stock' => __('Waiting for stock'),
        'skipped_no_stock' => __('No stock today'),
        'error' => __('Error'),
        'running' => __('Running'),
    ];
    $th = 'px-3 py-3 text-xs font-semibold uppercase tracking-wide text-slate-500';
@endphp
<div class="space-y-6">
    <x-ui.page-header :title="__('Order rules')" :subtitle="__('How much sales history the forecast uses, how many days an order covers, and the daily automatic draft order of each shop.')" />

    @unless($telegramConfigured)
        <p class="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-800" data-no-telegram>{{ __('The Telegram bot token (TELEGRAM_BOT_TOKEN) is not set. Drafts are still created, but no message is sent to the responsible people.') }}</p>
    @endunless

    <x-ui.card>
        <h2 class="text-base font-semibold text-slate-900">{{ __('Default for all shops') }}</h2>
        <p class="mb-4 mt-1 text-sm text-slate-500">{{ __('Every shop uses these values unless it has its own setting below. Every day at the run time the system forecasts the shop, writes a draft order and sends its link to the shop\'s responsible people in Telegram (connect the Telegram of each user in Users; inactive users are skipped). A shop that already has a draft is skipped; the draft is never changed.') }}</p>

        <form wire:submit="saveDefault" class="space-y-4">
            <label class="flex items-center gap-2 text-sm font-medium text-slate-700">
                <input type="checkbox" wire:model="defEnabled" class="rounded border-slate-300 text-brand-700 focus:ring-brand-600" />
                {{ __('Create a draft order automatically every day') }}
            </label>

            <div class="grid gap-4 sm:grid-cols-3">
                <div>
                    <x-ui.select name="defHistory" :label="__('History window')" wire:model="defHistory">
                        @foreach($historyOptions as $d)
                            <option value="{{ $d }}">{{ __(':days days', ['days' => $d]) }}</option>
                        @endforeach
                    </x-ui.select>
                    <p class="mt-1 text-xs text-slate-500">{{ __('Days of past sales the forecast looks at. Shorter reacts faster, longer is steadier.') }}</p>
                </div>
                <div>
                    <x-ui.input name="defHorizon" type="number" min="1" max="7" :label="__('Cover, days')" wire:model="defHorizon" />
                    <p class="mt-1 text-xs text-slate-500">{{ __('Days of sales an order must cover (1-7).') }}</p>
                </div>
                <div>
                    <x-ui.input name="defRunTime" type="time" :label="__('Run time')" wire:model="defRunTime" />
                    <p class="mt-1 text-xs text-slate-500">{{ __('Server time. The draft is made after the day\'s stock arrives from 1C.') }}</p>
                </div>
            </div>

            <div>
                <x-ui.label>{{ __('Weekdays') }}</x-ui.label>
                <div class="mt-1 flex flex-wrap gap-3">
                    @foreach($days as $value => $label)
                        <label class="flex items-center gap-1.5 text-sm text-slate-700">
                            <input type="checkbox" wire:model="defWeekdays" value="{{ $value }}" class="rounded border-slate-300 text-brand-700 focus:ring-brand-600" />{{ $label }}
                        </label>
                    @endforeach
                </div>
                <p class="mt-1 text-xs text-slate-500">{{ __('None ticked means every day.') }}</p>
            </div>

            <div class="flex flex-wrap items-center justify-end gap-3">
                @if($defSaved)<span class="text-sm text-brand-700">{{ __('Saved') }}</span>@endif
                <x-ui.button type="submit">{{ __('Save') }}</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card padding="p-0">
        <div class="border-b border-slate-200 p-4 sm:p-6">
            <h2 class="text-base font-semibold text-slate-900">{{ __('Shops') }}</h2>
            <p class="mt-1 text-sm text-slate-500">{{ __('A shop can override any of the values above. Values in grey are inherited from the default. The last result of the automatic run is shown for each shop.') }}</p>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200">
                <thead class="bg-sand-50">
                    <tr>
                        <th class="{{ $th }} text-left">{{ __('Shop') }}</th>
                        <th class="{{ $th }} text-left">{{ __('Automatic draft') }}</th>
                        <th class="{{ $th }} text-right">{{ __('History window') }}</th>
                        <th class="{{ $th }} text-right">{{ __('Cover, days') }}</th>
                        <th class="{{ $th }} text-left">{{ __('Run time') }}</th>
                        <th class="{{ $th }} text-left">{{ __('Telegram') }}</th>
                        <th class="{{ $th }} text-left">{{ __('Last run') }}</th>
                        <th class="{{ $th }}"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($shops as $shop)
                        @php
                            $e = $effective[$shop->id];
                            $o = $own->get($shop->id);
                            $cell = fn ($col) => $o?->{$col} === null ? 'text-slate-400' : 'font-medium text-slate-900';
                        @endphp
                        <tr wire:key="shop-{{ $shop->id }}">
                            <td class="px-3 py-3 text-sm font-medium text-slate-900">{{ $shop->name }}</td>
                            <td class="px-3 py-3 text-sm {{ $cell('enabled') }}">
                                {{ $e['enabled'] ? __('On') : __('Off') }}
                                <span class="block text-xs font-normal text-slate-500">{{ $e['weekdays'] === [] ? __('Every day') : collect($e['weekdays'])->map(fn ($d) => $days[$d])->implode(', ') }}</span>
                            </td>
                            <td class="px-3 py-3 text-right text-sm tabular-nums {{ $cell('history_days') }}">{{ $e['history_days'] }}</td>
                            <td class="px-3 py-3 text-right text-sm tabular-nums {{ $cell('horizon_days') }}">{{ $e['horizon_days'] }}</td>
                            <td class="px-3 py-3 text-sm tabular-nums {{ $cell('run_time') }}">{{ $e['run_time'] }}</td>
                            <td class="px-3 py-3 text-sm">
                                <span class="block text-[11px] text-slate-400">{{ __('Inactive users are skipped.') }}</span>
                                @forelse($responsible[$shop->id] as $person)
                                    <span class="block text-xs {{ filled($person->telegram_chat_id) ? 'text-slate-700' : 'text-slate-400' }}">{{ $person->name }} <span class="{{ filled($person->telegram_chat_id) ? 'text-brand-700' : 'text-amber-600' }}">{{ filled($person->telegram_chat_id) ? __('Telegram on') : __('no Telegram') }}</span></span>
                                @empty
                                    <span class="block text-xs text-amber-700" data-no-responsible>{{ __('No responsible user is assigned to this shop.') }}</span>
                                @endforelse
                                @if($responsible[$shop->id]->contains(fn ($p) => filled($p->telegram_chat_id)))
                                    <button type="button" wire:click="sendTest({{ $shop->id }})" wire:loading.attr="disabled" wire:target="sendTest" class="mt-1 text-xs font-medium text-brand-700 hover:underline">{{ __('Send test message') }}</button>
                                @elseif($responsible[$shop->id]->isNotEmpty())
                                    <span class="block text-xs text-amber-700" data-no-chat>{{ __('Nobody has a Telegram chat id: set it in Users.') }}</span>
                                @endif
                                @if($testKey === (string) $shop->id && $testMessage !== '')
                                    <span class="block text-xs {{ $testOk ? 'text-brand-700' : 'text-red-600' }}">{{ $testMessage }}</span>
                                @endif
                            </td>
                            <td class="max-w-xs px-3 py-3 text-sm">
                                @if($e['last_run_status'])
                                    <x-ui.badge :variant="$statusVariant[$e['last_run_status']] ?? 'default'">{{ $statusLabel[$e['last_run_status']] ?? $e['last_run_status'] }}</x-ui.badge>
                                    @if($e['last_run_date'])<span class="ml-1 text-xs text-slate-400">{{ $e['last_run_date'] }}</span>@endif
                                    @if($e['last_run_note'])<span class="mt-1 block text-xs text-slate-500">{{ $e['last_run_note'] }}</span>@endif
                                @else
                                    <span class="text-slate-400">—</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-3 py-3 text-right text-sm">
                                <button type="button" wire:click="edit({{ $shop->id }})" class="font-medium text-brand-700 hover:underline">{{ __('Edit') }}</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-ui.card>

    <x-ui.modal :show="$showModal" :title="__('Order rules of the shop')">
        <form wire:submit="saveShop" class="space-y-4">
            <p class="text-sm text-slate-500">{{ __('Leave a field on "Use default" to inherit it from the default above.') }}</p>

            <x-ui.select name="enabled" :label="__('Automatic draft')" wire:model="enabled">
                <option value="">{{ __('Use default') }}</option>
                <option value="1">{{ __('On') }}</option>
                <option value="0">{{ __('Off') }}</option>
            </x-ui.select>

            <div class="grid gap-4 sm:grid-cols-3">
                <x-ui.select name="history" :label="__('History window')" wire:model="history">
                    <option value="">{{ __('Use default') }}</option>
                    @foreach($historyOptions as $d)
                        <option value="{{ $d }}">{{ __(':days days', ['days' => $d]) }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.select name="horizon" :label="__('Cover, days')" wire:model="horizon">
                    <option value="">{{ __('Use default') }}</option>
                    @foreach(range(1, 7) as $d)
                        <option value="{{ $d }}">{{ $d }}</option>
                    @endforeach
                </x-ui.select>
                <div>
                    <x-ui.input name="runTime" type="time" :label="__('Run time')" wire:model="runTime" />
                    <button type="button" wire:click="$set('runTime', '')" class="mt-1 text-xs text-slate-500 hover:underline">{{ __('Use default') }}</button>
                </div>
            </div>

            <div>
                <x-ui.select name="weekdayMode" :label="__('Weekdays')" wire:model.live="weekdayMode">
                    <option value="inherit">{{ __('Use default') }}</option>
                    <option value="all">{{ __('Every day') }}</option>
                    <option value="selected">{{ __('Selected days') }}</option>
                </x-ui.select>
                @if($weekdayMode === 'selected')
                    <div class="mt-2 flex flex-wrap gap-3">
                        @foreach($days as $value => $label)
                            <label class="flex items-center gap-1.5 text-sm text-slate-700">
                                <input type="checkbox" wire:model="weekdays" value="{{ $value }}" class="rounded border-slate-300 text-brand-700 focus:ring-brand-600" />{{ $label }}
                            </label>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-between">
                <button type="button" wire:click="resetShop({{ (int) $shopId }})" wire:confirm="{{ __('Remove all overrides of this shop?') }}" class="py-2 text-sm text-red-600 hover:underline">{{ __('Use default for everything') }}</button>
                <div class="flex flex-col-reverse gap-2 sm:flex-row sm:gap-3 [&>*]:max-sm:h-12 [&>*]:max-sm:w-full [&>*]:max-sm:text-base">
                    <x-ui.button type="button" variant="secondary" wire:click="closeModal">{{ __('Cancel') }}</x-ui.button>
                    <x-ui.button type="submit">{{ __('Save') }}</x-ui.button>
                </div>
            </div>
        </form>
    </x-ui.modal>
</div>
