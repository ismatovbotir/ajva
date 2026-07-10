@props(['variant' => 'default'])
@php
    $variants = [
        'default' => 'bg-slate-100 text-slate-700',
        'success' => 'bg-green-100 text-green-700',
        'warning' => 'bg-amber-100 text-amber-700',
        'danger' => 'bg-red-100 text-red-700',
        'info' => 'bg-blue-100 text-blue-700',
    ];

    $classes = 'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium '.($variants[$variant] ?? $variants['default']);
@endphp
<span {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</span>
