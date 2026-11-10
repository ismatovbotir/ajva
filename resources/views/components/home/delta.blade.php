@props(['pct' => null, 'label' => ''])
@php
    // One comparison chip: arrow + signed percent + what it is compared with. Never colour-only (the arrow and the sign carry the direction).
    $status = \App\Monitor\Comparison::deltaStatus($pct);
    $tone = match (true) {
        $pct === null => 'bg-slate-100 text-slate-500',
        $status === 'bad' => 'bg-red-50 text-red-700',
        $status === 'warn' => 'bg-amber-50 text-amber-700',
        $pct >= 0 => 'bg-emerald-50 text-emerald-700',
        default => 'bg-slate-100 text-slate-700',
    };
    $text = $pct === null ? 'n/a' : ($pct >= 0 ? '▲ +' : '▼ −').number_format(abs($pct), 1).'%';
@endphp
<span {{ $attributes->merge(['class' => "inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-xs font-medium tabular-nums $tone"]) }} title="{{ $pct === null ? __('Not enough receipts in the comparison period.') : '' }}">
    <span class="font-normal opacity-80">{{ $label }}</span>
    <span>{{ $text }}</span>
</span>
