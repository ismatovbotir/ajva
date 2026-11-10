@props(['name', 'label' => null])
<div>
    @if($label)
        <x-ui.label :for="$name">{{ $label }}</x-ui.label>
    @endif
    <select
        name="{{ $name }}"
        id="{{ $name }}"
        {{ $attributes->merge(['class' => 'block h-11 w-full rounded-lg border-slate-300 bg-white px-3.5 text-[15px] shadow-sm placeholder:text-slate-400 focus:border-primary-500 focus:ring-primary-500']) }}
    >
        {{ $slot }}
    </select>
    <x-ui.error :name="$name" />
</div>
