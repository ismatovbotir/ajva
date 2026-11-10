@props(['variant' => 'default'])
@php
    $variants = [
        'default' => 'bg-slate-100 text-slate-600 ring-slate-500/15',
        'success' => 'bg-brand-50 text-brand-700 ring-brand-600/20',
        'warning' => 'bg-amber-50 text-amber-700 ring-amber-600/20',
        'danger' => 'bg-red-50 text-red-700 ring-red-600/20',
        'info' => 'bg-primary-50 text-primary-700 ring-primary-600/20',
    ];

    $classes = 'inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset '.($variants[$variant] ?? $variants['default']);
@endphp
<span {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</span>
