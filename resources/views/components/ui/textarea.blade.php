@props(['name', 'label' => null, 'rows' => 3])
<div>
    @if($label)
        <x-ui.label :for="$name">{{ $label }}</x-ui.label>
    @endif
    <textarea
        name="{{ $name }}"
        id="{{ $name }}"
        rows="{{ $rows }}"
        {{ $attributes->merge(['class' => 'block w-full rounded-lg border-slate-300 bg-white px-3.5 py-2.5 text-[15px] shadow-sm placeholder:text-slate-400 focus:border-primary-500 focus:ring-primary-500']) }}
    >{{ $slot }}</textarea>
    <x-ui.error :name="$name" />
</div>
