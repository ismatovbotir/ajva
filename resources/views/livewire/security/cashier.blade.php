@php
    $th = 'px-3 py-2 text-xs font-semibold uppercase tracking-wide text-slate-500';
    $pct = fn ($v) => $v === null ? '-' : number_format((float) $v, 1, '.', ' ').' %';
    $money = fn ($n) => number_format((float) $n, 0, '.', ' ');
    $reasons = function ($r) use ($deepPct) {
        $out = [];
        if (! $r->active) { $out[] = __('Cancelled'); }
        elseif (! $r->sell) { $out[] = __('Refund'); }
        else {
            if ((float) $r->total <= 0) { $out[] = __('Total of zero or less'); }
            if ((float) $r->discount < 0) { $out[] = __('Negative discount'); }
            if ((float) $r->total + (float) $r->discount > 0 && (float) $r->discount * 100 >= $deepPct * ((float) $r->total + (float) $r->discount)) { $out[] = __('Deep discount'); }
        }
        return $out;
    };
@endphp
<div>
    <x-ui.page-header :title="$subject" :subtitle="$detail['shop'].' · '.__('Last :days days (:from to :to), as of :time.', ['days' => config('security.detail_days'), 'from' => $detail['from'], 'to' => $detail['to'], 'time' => now()->format('Y-m-d H:i')])">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('security.warnings')">{{ __('Back to warnings') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <p class="mb-4 text-xs text-slate-500">
        {{ __(':n receipts in the period, :sales sale receipts, sales :sum.', ['n' => $detail['receipts'], 'sales' => $detail['sale_count'], 'sum' => $money($detail['sale_sum'])]) }}
        @unless($detail['enough'])
            <span class="font-medium text-amber-700">{{ __('Fewer than :min receipts: rates are shown but not judged.', ['min' => $minReceipts]) }}</span>
        @endunless
    </p>

    <h2 class="mb-2 text-base font-semibold text-slate-900">{{ __('Against the rest of the chain') }}</h2>
    <x-ui.card padding="p-0" class="mb-6">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200">
                <thead><tr>
                    <th class="{{ $th }} text-left">{{ __('Metric') }}</th>
                    <th class="{{ $th }} text-right">{{ __('This one') }}</th>
                    <th class="{{ $th }} text-right">{{ __('Rest of the chain') }}</th>
                    <th class="{{ $th }} text-right">{{ __('Events / sample') }}</th>
                    <th class="{{ $th }} text-left">{{ __('Signal') }}</th>
                </tr></thead>
                <tbody class="divide-y divide-slate-100 text-sm">
                @foreach($detail['metrics'] as $m)
                    <tr>
                        <td class="px-3 py-2">{{ __($m['label']) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ $pct($m['rate']) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ $pct($m['base']) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ $m['events'] }} / {{ $m['sample'] }}</td>
                        <td class="px-3 py-2">
                            @if($m['judged'])
                                <x-ui.badge variant="warning"><x-icon name="exclamation" class="mr-1 h-3.5 w-3.5" />{{ __('Above the chain') }}</x-ui.badge>
                            @else
                                <span class="text-xs text-slate-500">{{ __('No signal') }}</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                <tr>
                    <td class="px-3 py-2">{{ __('Sale receipts outside normal hours') }}</td>
                    <td class="px-3 py-2 text-right tabular-nums">{{ $detail['off_hours']['count'] }}</td>
                    <td class="px-3 py-2 text-right tabular-nums">-</td>
                    <td class="px-3 py-2 text-right tabular-nums" colspan="2">
                        @if($detail['off_hours']['hours'] === [])
                            <span class="text-xs text-slate-500">{{ __('The shop has too little history to know its normal hours.') }}</span>
                        @else
                            <span class="text-xs text-slate-500">{{ __('Hours below :pct % of the shop volume: :hours', ['pct' => config('security.off_hours_share_pct'), 'hours' => implode(', ', array_map(fn ($h) => sprintf('%02d', $h), $detail['off_hours']['hours']))]) }}</span>
                        @endif
                    </td>
                </tr>
                </tbody>
            </table>
        </div>
    </x-ui.card>

    <h2 class="mb-2 text-base font-semibold text-slate-900">{{ __('By day') }}</h2>
    <x-ui.card padding="p-0" class="mb-6">
        <div class="max-h-96 overflow-auto">
            <table class="min-w-full divide-y divide-slate-200">
                <thead><tr>
                    <th class="{{ $th }} text-left">{{ __('Date') }}</th>
                    <th class="{{ $th }} text-right">{{ __('Sale receipts') }}</th>
                    <th class="{{ $th }} text-right">{{ __('Sales') }}</th>
                    <th class="{{ $th }} text-right">{{ __('Refunds') }}</th>
                    <th class="{{ $th }} text-right">{{ __('Cancelled') }}</th>
                    <th class="{{ $th }} text-right">{{ __('Deep discounts') }}</th>
                    <th class="{{ $th }} text-right">{{ __('Off hours') }}</th>
                </tr></thead>
                <tbody class="divide-y divide-slate-100 text-sm">
                @foreach($detail['days'] as $d)
                    <tr>
                        <td class="px-3 py-1.5"><a class="text-brand-700 hover:underline" href="{{ route('receipts.index', ['date' => $d['date']]) }}">{{ $d['date'] }}</a></td>
                        <td class="px-3 py-1.5 text-right tabular-nums">{{ $d['sales'] }}</td>
                        <td class="px-3 py-1.5 text-right tabular-nums">{{ $money($d['sale_sum']) }}</td>
                        <td class="px-3 py-1.5 text-right tabular-nums">{{ $d['refunds'] }}</td>
                        <td class="px-3 py-1.5 text-right tabular-nums">{{ $d['cancelled'] }}</td>
                        <td class="px-3 py-1.5 text-right tabular-nums">{{ $d['deep'] }}</td>
                        <td class="px-3 py-1.5 text-right tabular-nums">{{ $d['off_hours'] }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </x-ui.card>

    <h2 class="mb-2 text-base font-semibold text-slate-900">{{ __('Receipts to review') }}</h2>
    @if($receipts->isEmpty())
        <x-ui.empty-state :title="__('No receipts to review')" :description="__('No refund, cancelled or deep-discount receipts in the period.')" />
    @else
        <div class="space-y-2 md:hidden">
            @foreach($receipts as $r)
                <x-ui.card wire:key="fc-{{ $r->id }}">
                    <a href="{{ route('receipts.show', ['receipt' => $r->id]) }}" target="_blank" rel="noopener" class="text-sm font-medium text-brand-700 hover:underline">#{{ $r->number }}</a>
                    <p class="text-xs text-slate-500">{{ substr((string) $r->created_at, 0, 16) }} · {{ $money($r->total) }}</p>
                    <p class="mt-1 text-xs">{{ implode(', ', $reasons($r)) }}</p>
                </x-ui.card>
            @endforeach
        </div>
        <x-ui.card padding="p-0" class="hidden md:block">
            <table class="min-w-full divide-y divide-slate-200">
                <thead><tr>
                    <th class="{{ $th }} text-left">{{ __('Receipt') }}</th>
                    <th class="{{ $th }} text-left">{{ __('Time') }}</th>
                    <th class="{{ $th }} text-right">{{ __('Total') }}</th>
                    <th class="{{ $th }} text-right">{{ __('Discount') }}</th>
                    <th class="{{ $th }} text-left">{{ __('Why') }}</th>
                </tr></thead>
                <tbody class="divide-y divide-slate-100 text-sm">
                @foreach($receipts as $r)
                    <tr wire:key="fr-{{ $r->id }}">
                        <td class="px-3 py-2"><a href="{{ route('receipts.show', ['receipt' => $r->id]) }}" target="_blank" rel="noopener" class="font-medium text-brand-700 hover:underline">#{{ $r->number }}</a></td>
                        <td class="px-3 py-2 tabular-nums">{{ substr((string) $r->created_at, 0, 16) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ $money($r->total) }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ $money($r->discount) }}</td>
                        <td class="px-3 py-2">{{ implode(', ', $reasons($r)) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </x-ui.card>
        <div class="mt-3">{{ $receipts->links() }}</div>
    @endif
</div>
