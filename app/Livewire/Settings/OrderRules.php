<?php

namespace App\Livewire\Settings;

use App\Models\OrderRule;
use App\Models\Shop;
use App\Services\OrderTelegram;
use App\Support\Warehouse;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Settings > Order rules (admin only): the chain default (shop_id NULL) and an optional override per shop for
 * the history window of the forecast, the order horizon, the daily automatic draft (time, weekdays, on/off) and who
 * gets the Telegram message (the shop's operators; the chat id lives on the user). A shop override only sets what it changes; everything else is inherited.
 * The last automatic run result of every shop is listed (written by orders:auto-generate).
 */
#[Layout('components.layouts.app', ['title' => 'Order rules'])]
class OrderRules extends Component
{
    // Chain default form
    public bool $defEnabled = false;

    public string $defHistory = '28';

    public string $defHorizon = '';

    public string $defRunTime = OrderRule::DEFAULT_RUN_TIME;

    /** @var array<int, string> */
    public array $defWeekdays = [];

    public bool $defSaved = false;

    // Shop override modal ('' = inherit)
    public bool $showModal = false;

    public ?int $shopId = null;

    public string $enabled = '';

    public string $history = '';

    public string $horizon = '';

    public string $runTime = '';

    /** inherit | all | selected */
    public string $weekdayMode = 'inherit';

    /** @var array<int, string> */
    public array $weekdays = [];

    // Result of "Send test message" (a shop id)
    public string $testKey = '';

    public bool $testOk = false;

    public string $testMessage = '';

    /**
     * Runs on every request, including Livewire updates, which skip the route
     * middleware - so a demoted admin can't keep using an already-open page.
     */
    public function boot(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    public function mount(): void
    {
        $row = OrderRule::defaultRow();
        $this->defEnabled = (bool) $row?->enabled;
        $this->defHistory = (string) ($row?->history_days ?? OrderRule::DEFAULT_HISTORY_DAYS);
        $this->defHorizon = (string) ($row?->horizon_days ?? config('inventory.order_horizon_days', 3));
        $this->defRunTime = $row?->run_time ?? OrderRule::DEFAULT_RUN_TIME;
        $this->defWeekdays = array_map('strval', $row?->weekdays ?? []);
    }

    /** @return array<string, mixed> */
    private function commonRules(string $prefix, bool $nullable): array
    {
        $req = $nullable ? 'nullable' : 'required';

        return [
            $prefix.'History' => [$req, Rule::in(array_map('strval', OrderRule::HISTORY_OPTIONS))],
            $prefix.'Horizon' => [$req, 'integer', 'between:1,7'],
            $prefix.'RunTime' => [$req, 'date_format:H:i'],
        ];
    }

    public function saveDefault(): void
    {
        $this->validate($this->commonRules('def', false) + ['defEnabled' => ['boolean'], 'defWeekdays' => ['array'], 'defWeekdays.*' => ['integer', 'between:0,6']]);

        $row = OrderRule::defaultRow() ?? new OrderRule;
        $row->fill([
            'shop_id' => null,
            'enabled' => $this->defEnabled,
            'history_days' => (int) $this->defHistory,
            'horizon_days' => (int) $this->defHorizon,
            'run_time' => $this->defRunTime,
            'weekdays' => $this->weekdayList($this->defWeekdays),
        ])->save();

        $this->defSaved = true;
    }

    public function updated(string $name): void
    {
        if (str_starts_with($name, 'def')) {
            $this->defSaved = false;
        }
    }

    public function edit(int $shopId): void
    {
        $this->assertShop($shopId);
        $this->resetErrorBag();

        $row = OrderRule::query()->where('shop_id', $shopId)->first();
        $this->shopId = $shopId;
        $this->enabled = $row?->enabled === null ? '' : ($row->enabled ? '1' : '0');
        $this->history = (string) ($row?->history_days ?? '');
        $this->horizon = (string) ($row?->horizon_days ?? '');
        $this->runTime = (string) ($row?->run_time ?? '');
        $this->weekdayMode = $row?->weekdays === null ? 'inherit' : ($row->weekdays === [] ? 'all' : 'selected');
        $this->weekdays = array_map('strval', $row?->weekdays ?? []);
        $this->showModal = true;
    }

    public function saveShop(): void
    {
        $this->assertShop((int) $this->shopId);
        $this->validate([
            'enabled' => ['nullable', Rule::in(['0', '1'])],
            'history' => ['nullable', Rule::in(array_map('strval', OrderRule::HISTORY_OPTIONS))],
            'horizon' => ['nullable', 'integer', 'between:1,7'],
            'runTime' => ['nullable', 'date_format:H:i'],
            'weekdayMode' => [Rule::in(['inherit', 'all', 'selected'])],
            'weekdays' => ['array'],
            'weekdays.*' => ['integer', 'between:0,6'],
        ]);

        $row = OrderRule::ownRow((int) $this->shopId);
        $row->fill([
            'enabled' => $this->enabled === '' ? null : $this->enabled === '1',
            'history_days' => $this->history === '' ? null : (int) $this->history,
            'horizon_days' => $this->horizon === '' ? null : (int) $this->horizon,
            'run_time' => $this->runTime === '' ? null : $this->runTime,
            'weekdays' => match ($this->weekdayMode) {
                'all' => [],
                'selected' => $this->weekdayList($this->weekdays),
                default => null,
            },
        ])->save();

        $this->closeModal();
    }

    /** Drops every override of the shop so it inherits the default again (the last run result stays). */
    public function resetShop(int $shopId): void
    {
        $this->assertShop($shopId);

        OrderRule::query()->where('shop_id', $shopId)->update([
            'enabled' => null,
            'history_days' => null,
            'horizon_days' => null,
            'run_time' => null,
            'weekdays' => null,
        ]);
        $this->closeModal();
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->shopId = null;
        $this->resetErrorBag();
    }

    /** Sends a plain test message to every responsible user of the shop who has a Telegram chat id. */
    public function sendTest(int $shopId): void
    {
        $this->assertShop($shopId);
        $this->testKey = (string) $shopId;
        $telegram = app(OrderTelegram::class);

        if (! $telegram->configured()) {
            $this->testOk = false;
            $this->testMessage = __('The bot token (TELEGRAM_BOT_TOKEN) is not set.');

            return;
        }

        $users = OrderTelegram::recipients($shopId);
        if ($users->isEmpty()) {
            $this->testOk = false;
            $this->testMessage = __('This shop has no responsible user with a Telegram chat id.');

            return;
        }

        $label = (string) Shop::query()->whereKey($shopId)->value('name');
        $failed = [];
        foreach ($users as $user) {
            $result = $telegram->sendTest(trim((string) $user->telegram_chat_id), $label);
            $result['ok'] || $failed[] = $user->name.' ('.$result['error'].')';
        }

        $this->testOk = $failed === [];
        $this->testMessage = $failed === []
            ? __('Test message sent to :names.', ['names' => $users->pluck('name')->implode(', ')])
            : __('Sending failed for :names.', ['names' => implode('; ', $failed)]);
    }

    /** @param  array<int, mixed>  $days  @return array<int, int> */
    private function weekdayList(array $days): array
    {
        $list = array_values(array_unique(array_map('intval', $days)));
        sort($list);

        return count($list) === 7 ? [] : $list;
    }

    private function assertShop(int $shopId): void
    {
        abort_unless(Shop::query()->whereKey($shopId)->exists() && ! Warehouse::isWarehouse($shopId), 404);
    }

    protected function validationAttributes(): array
    {
        return [
            'defHistory' => __('History window'),
            'defHorizon' => __('Cover, days'),
            'defRunTime' => __('Run time'),
            'history' => __('History window'),
            'horizon' => __('Cover, days'),
            'runTime' => __('Run time'),
        ];
    }

    public function render()
    {
        $warehouseId = Warehouse::shopId();
        $shops = Shop::query()->when($warehouseId !== null, fn ($q) => $q->whereKeyNot($warehouseId))->orderBy('name')->get(['id', 'name']);
        $effective = OrderRule::effective($shops->pluck('id')->all());
        $own = OrderRule::query()->whereIn('shop_id', $shops->pluck('id'))->get()->keyBy('shop_id');

        return view('livewire.settings.order-rules', [
            'shops' => $shops,
            'effective' => $effective,
            'own' => $own,
            'responsible' => OrderTelegram::responsibleByShop($shops->pluck('id')->all()),
            'telegramConfigured' => app(OrderTelegram::class)->configured(),
            'days' => [1 => __('Mon'), 2 => __('Tue'), 3 => __('Wed'), 4 => __('Thu'), 5 => __('Fri'), 6 => __('Sat'), 0 => __('Sun')],
            'historyOptions' => OrderRule::HISTORY_OPTIONS,
            'defaultHorizon' => (int) config('inventory.order_horizon_days', 3),
        ]);
    }
}
