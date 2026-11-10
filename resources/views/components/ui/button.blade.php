@props(['type' => 'button', 'variant' => 'primary', 'href' => null])
@php
    $base = 'inline-flex items-center justify-center gap-2 rounded-md px-3.5 py-1.5 text-sm font-medium transition focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-1 disabled:cursor-not-allowed disabled:opacity-50';

    $variants = [
        'primary' => 'bg-primary-600 text-white shadow-sm hover:bg-primary-700',
        'secondary' => 'border border-slate-200 bg-white text-slate-700 shadow-sm hover:bg-slate-50 hover:border-slate-300',
        'danger' => 'bg-red-600 text-white shadow-sm hover:bg-red-500 focus-visible:ring-red-500',
    ];

    $classes = $base.' '.($variants[$variant] ?? $variants['primary']);
@endphp
@if($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</button>
@endif
