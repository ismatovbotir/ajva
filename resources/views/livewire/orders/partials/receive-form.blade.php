{{-- "Confirm receipt" lines: received quantity (prefilled with the ordered one) and a reason that appears when it differs.
     Needs $items (order lines with item), $phone (bool). The component uses App\Livewire\Concerns\ReceivesOrder. --}}
@php
    $fmt = fn ($n) => $n === null ? '—' : rtrim(rtrim(number_format((float) $n, 3, '.', ' '), '0'), '.');
    $reasons = \App\Services\OrderReceiving::reasonLabels();
@endphp
@error('receive')<p class="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700" data-receive-error>{{ $message }}</p>@enderror
<ul class="space-y-2" data-receive-lines>
    @foreach($items as $line)
        <li wire:key="recv-{{ $line->id }}" class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm"
            x-data="{ ordered: {{ (float) $line->qty }} }">
            <div class="flex items-center gap-3">
                <div class="min-w-0 flex-1">
                    <p class="{{ $phone ? 'text-[0.95rem]' : 'text-sm' }} font-medium leading-snug text-slate-900">{{ $line->item?->name }}</p>
                    <p class="mt-0.5 text-xs text-slate-500">{{ __('Ordered') }}: {{ $fmt($line->qty) }}</p>
                </div>
                <input type="number" inputmode="decimal" min="0" step="any" wire:model="recvQty.{{ $line->item_id }}" onfocus="this.select()"
                       aria-label="{{ __('Received') }}: {{ $line->item?->name }}"
                       class="{{ $phone ? 'h-12 w-24 text-lg' : 'h-10 w-28 text-sm' }} shrink-0 rounded-xl border border-slate-300 px-2 text-right font-semibold tabular-nums focus:border-brand-600 focus:ring-brand-600" />
            </div>
            <div class="mt-2" x-show="Number($wire.recvQty[{{ $line->item_id }}]) !== ordered" x-cloak>
                <select wire:model="recvReason.{{ $line->item_id }}" aria-label="{{ __('Reason') }}"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-600 focus:ring-brand-600">
                    <option value="">{{ __('Reason (optional)') }}</option>
                    @foreach($reasons as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </li>
    @endforeach
</ul>
