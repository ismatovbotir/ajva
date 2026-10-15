{{-- Telegram delivery state of a draft: Sent (time, recipients reached) / Not sent / Failed / No recipients. Needs $order. --}}
@php
    $tgStatus = $order->telegram_status;
@endphp
<span data-telegram-badge="{{ $tgStatus ?? 'none' }}" class="inline-flex flex-wrap items-center gap-x-1.5 gap-y-0.5">
    @if($tgStatus === 'sent')
        <x-ui.badge variant="success">{{ __('Sent') }}@if($order->telegram_recipients_ok > 0) · {{ $order->telegram_recipients_ok }}@endif</x-ui.badge>
        @if($order->telegram_sent_at)<span class="text-xs text-slate-400">{{ $order->telegram_sent_at->format('d.m H:i') }}</span>@endif
    @elseif($tgStatus === 'failed')
        <x-ui.badge variant="danger">{{ __('Failed') }}@if($order->telegram_recipients_ok > 0) · {{ __(':count reached', ['count' => $order->telegram_recipients_ok]) }}@endif</x-ui.badge>
        <span class="block max-w-[14rem] truncate text-xs text-red-600" title="{{ $order->telegram_note }}">{{ $order->telegram_note }}</span>
    @elseif($tgStatus === 'no_recipients')
        <x-ui.badge variant="warning">{{ __('No recipients') }}</x-ui.badge>
    @elseif($tgStatus === 'no_token')
        <x-ui.badge variant="warning">{{ __('Bot not set up') }}</x-ui.badge>
    @else
        <x-ui.badge>{{ __('Not sent') }}</x-ui.badge>
    @endif
</span>
