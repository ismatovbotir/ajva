{{-- The exceptions of an order (reasons it needs an admin): $reasons = list of {code, severity, item_id, text}. --}}
@if(! empty($reasons))
    <ul class="mt-3 space-y-1.5" data-exceptions>
        @foreach($reasons as $reason)
            <li class="flex items-start gap-2 text-sm">
                <x-ui.badge :variant="($reason['severity'] ?? '') === 'danger' ? 'danger' : 'warning'">{{ ($reason['severity'] ?? '') === 'danger' ? __('High') : __('Check') }}</x-ui.badge>
                <span class="text-slate-700">{{ $reason['text'] ?? '' }}</span>
            </li>
        @endforeach
    </ul>
@endif
