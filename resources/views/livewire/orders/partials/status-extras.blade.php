{{-- Extra badges next to the status of an order in the list: waiting for approval / sent back / overdue / expired / received. --}}
@if($order->isAwaitingApproval())<x-ui.badge variant="warning" data-badge-approval>{{ __('Waiting for approval') }}</x-ui.badge>@endif
@if($order->review_status === 'rejected' && $order->status === \App\Enums\OrderStatus::Draft)<x-ui.badge variant="danger" data-badge-rejected>{{ __('Sent back') }}</x-ui.badge>@endif
@if(\App\Services\OrderReceiving::isOverdue($order))<x-ui.badge variant="danger">{{ __('Overdue') }}</x-ui.badge>@endif
@if($order->close_reason === 'expired' && $order->isAwaitingReceipt())<x-ui.badge variant="warning">{{ __('Expired') }}</x-ui.badge>@endif
@if($order->received_at)<x-ui.badge variant="success">{{ __('Received') }}</x-ui.badge>@endif
