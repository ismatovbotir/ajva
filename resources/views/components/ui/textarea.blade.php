@props(['name', 'label' => null, 'rows' => 3])
<div>
    @if($label)
        <x-ui.label :for="$name">{{ $label }}</x-ui.label>
    @endif
    <textarea
        name="{{ $name }}"
        id="{{ $name }}"
        rows="{{ $rows }}"
        {{ $attributes->merge(['class' => 'block w-full rounded-lg border-slate-300 shadow-sm focus:border-brand-600 focus:ring-brand-600 sm:text-sm']) }}
    >{{ $slot }}</textarea>
    <x-ui.error :name="$name" />
</div>
