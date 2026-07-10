@props(['name', 'label' => null, 'type' => 'text'])
<div>
    @if($label)
        <x-ui.label :for="$name">{{ $label }}</x-ui.label>
    @endif
    <input
        type="{{ $type }}"
        name="{{ $name }}"
        id="{{ $name }}"
        {{ $attributes->merge(['class' => 'block w-full rounded-lg border-slate-300 shadow-sm focus:border-brand-600 focus:ring-brand-600 sm:text-sm']) }}
    />
    <x-ui.error :name="$name" />
</div>
