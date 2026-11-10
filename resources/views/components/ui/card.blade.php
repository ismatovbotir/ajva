@props(['padding' => 'p-4 sm:p-5'])
<div {{ $attributes->merge(['class' => "rounded-lg border border-slate-200 bg-white {$padding}"]) }}>
    {{ $slot }}
</div>
