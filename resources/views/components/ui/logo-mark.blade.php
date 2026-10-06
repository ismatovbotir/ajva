@props(['size' => 'h-9 w-9 text-lg'])
<span {{ $attributes->merge(['class' => "inline-flex shrink-0 items-center justify-center rounded-lg bg-brand-700 font-semibold text-white {$size}"]) }} aria-hidden="true">{{ mb_strtoupper(mb_substr(config('app.name'), 0, 1)) }}</span>
