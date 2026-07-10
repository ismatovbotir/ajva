@props(['title' => 'No records found', 'description' => null])
<div class="flex flex-col items-center justify-center rounded-xl border border-dashed border-slate-300 p-10 text-center">
    <p class="text-sm font-medium text-slate-900">{{ $title }}</p>
    @if($description)
        <p class="mt-1 text-sm text-slate-500">{{ $description }}</p>
    @endif
    @isset($actions)
        <div class="mt-4">{{ $actions }}</div>
    @endisset
</div>
