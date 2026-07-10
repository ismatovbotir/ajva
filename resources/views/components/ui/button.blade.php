@props(['type' => 'button', 'variant' => 'primary', 'href' => null])
@php
    $base = 'inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2 text-sm font-medium transition focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50';

    $variants = [
        'primary' => 'bg-brand-700 text-white hover:bg-brand-600 focus:ring-brand-500',
        'secondary' => 'border border-slate-300 bg-white text-slate-700 hover:bg-sand-50 focus:ring-brand-500',
        'danger' => 'bg-red-600 text-white hover:bg-red-500 focus:ring-red-500',
    ];

    $classes = $base.' '.($variants[$variant] ?? $variants['primary']);
@endphp
@if($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</button>
@endif
