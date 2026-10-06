@props(['padding' => 'p-4 sm:p-6'])
<div {{ $attributes->merge(['class' => "rounded-xl border border-slate-200 bg-surface shadow-sm {$padding}"]) }}>
    {{ $slot }}
</div>
