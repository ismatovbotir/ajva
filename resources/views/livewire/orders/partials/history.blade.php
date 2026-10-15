{{-- Timeline of the changes of an order (who, through which channel, what, when). Needs $events; $meId marks "You"; names only, never emails. --}}
@php
    $fmtQty = fn ($n) => $n === null ? '—' : rtrim(rtrim(number_format((float) $n, 3, '.', ' '), '0'), '.');
    $channelLabel = ['link' => __('via link'), 'panel' => __('in the panel'), 'system' => __('automatic')];
@endphp
<x-ui.card class="mt-4" data-history>
    <h3 class="mb-2 text-sm font-semibold text-slate-700">{{ __('Changes') }}</h3>
    @if($events->isEmpty())
        <p class="text-sm text-slate-500">{{ __('No changes recorded yet.') }}</p>
    @else
        <ol class="space-y-2 border-l border-slate-200 pl-4">
            @foreach($events as $event)
                @php
                    $who = $event->user_id === null ? __('System') : (($meId ?? null) !== null && $event->user_id === $meId ? __('You') : ($event->user?->name ?? __('Deleted user')));
                    $item = $event->item?->name;
                    $what = match ($event->action) {
                        'created' => __('Draft created (:count lines)', ['count' => $event->note]),
                        'lines_added' => __(':count lines added from the Ordering report', ['count' => $event->note]),
                        'qty_changed' => __(':item: :old → :new', ['item' => $item, 'old' => $fmtQty($event->old_qty), 'new' => $fmtQty($event->new_qty)]),
                        'line_removed' => __('Removed :item (was :qty)', ['item' => $item, 'qty' => $fmtQty($event->old_qty)]),
                        'line_added' => __('Added :item (:qty)', ['item' => $item, 'qty' => $fmtQty($event->new_qty)]),
                        'confirmed' => __('Confirmed the order'),
                        default => $event->action,
                    };
                @endphp
                <li class="relative text-sm" wire:key="event-{{ $event->id }}">
                    <span class="absolute -left-[1.3rem] top-1.5 h-2 w-2 rounded-full bg-brand-600"></span>
                    <p class="text-slate-800">{{ $what }}</p>
                    <p class="text-xs text-slate-500">{{ $who }} · {{ $channelLabel[$event->channel] ?? $event->channel }} · {{ $event->created_at?->format('d.m.Y H:i') }}</p>
                </li>
            @endforeach
        </ol>
    @endif
</x-ui.card>
