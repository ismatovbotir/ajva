{{-- Who put this line in the order: the system's suggestion or the manager (added by hand). --}}
@if($line->added_manually)
    <x-ui.badge variant="info" class="ml-1" data-line-source="manager">{{ __('By manager') }}</x-ui.badge>
@else
    <x-ui.badge class="ml-1" data-line-source="system">{{ __('By system') }}</x-ui.badge>
@endif
