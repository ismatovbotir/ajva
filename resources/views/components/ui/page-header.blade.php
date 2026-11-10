@props(['title', 'subtitle' => null])
<div class="mb-5 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
    <div class="min-w-0">
        <h1 class="text-lg font-semibold tracking-tight text-slate-900">{{ $title }}</h1>
        @if($subtitle)
            <p class="mt-0.5 text-sm text-slate-500">{{ $subtitle }}</p>
        @endif
    </div>
    @isset($actions)
        <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
    @endisset
</div>
