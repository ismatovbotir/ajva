{{-- Big number with the daily series of the last 8 days under it. --}}
@props(['w'])
@php
    use App\Monitor\Render;

    $values = array_map('floatval', $w['spark']);
    $max = max($values) ?: 1.0;
    $min = min($values);
    $span = ($max - $min) ?: 1.0;
    $n = count($values);
    $points = [];
    foreach ($values as $i => $v) {
        $points[] = number_format($i / ($n - 1) * 100, 2, '.', '').','.number_format(34 - ($v - $min) / $span * 30, 2, '.', '');
    }
@endphp
<div class="flex h-full flex-col justify-between gap-[0.3rem]">
    <p class="tabular truncate text-[2.2rem] font-bold leading-none {{ in_array($w['status'], ['bad', 'warn'], true) ? Render::text($w['status']) : 'text-white' }}">{{ $w['value'] }}</p>
    <svg viewBox="0 0 100 38" preserveAspectRatio="none" class="min-h-0 w-full flex-1 overflow-visible" role="img" aria-label="{{ __('Last 8 days') }}">
        <polyline points="{{ implode(' ', $points) }}" fill="none" stroke="#2a78d6" stroke-width="2" vector-effect="non-scaling-stroke" stroke-linejoin="round" />
    </svg>
    @if(isset($w['compare']))
        <x-monitor.compare :c="$w['compare']" />
    @endif
</div>
