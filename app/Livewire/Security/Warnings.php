<?php

namespace App\Livewire\Security;

use App\Services\SecurityWarnings;
use App\Support\ShopAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Security > Security warnings (admin only): signals to review raised from receipts, for a period and shops. Heavy, so it
 * calculates nothing until Generate is pressed; every press recalculates (the 10 minute cache is dropped first).
 */
#[Layout('components.layouts.app', ['title' => 'Security warnings'])]
class Warnings extends Component
{
    public string $dateFrom = '';

    public string $dateTo = '';

    /** @var array<int, int|string> Selected shop ids; empty = every shop the user may see. */
    public array $shopIds = [];

    public bool $generated = false;

    /** The inputs the current report was built from. */
    public array $applied = [];

    /** Filters of the generated list (in memory, no recalculation). */
    public string $severity = 'all';

    public string $type = 'all';

    public function boot(): void
    {
        // Livewire updates skip the route middleware: re-check on every request.
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    public function mount(): void
    {
        $this->dateTo = now()->toDateString();
        $this->dateFrom = now()->subDays(6)->toDateString();
    }

    public function generate(): void
    {
        $this->validate([
            'dateFrom' => ['required', 'date_format:Y-m-d'],
            'dateTo' => ['required', 'date_format:Y-m-d', 'after_or_equal:dateFrom'],
            'shopIds' => ['array'],
            'shopIds.*' => ['integer'],
        ]);

        $max = (int) config('security.max_days');
        if (Carbon::parse($this->dateFrom)->diffInDays(Carbon::parse($this->dateTo)) >= $max) {
            $this->addError('dateTo', __('The period can not be longer than :days days.', ['days' => $max]));

            return;
        }

        $this->applied = ['from' => $this->dateFrom, 'to' => $this->dateTo, 'shops' => array_map('intval', $this->shopIds)];
        Cache::forget($this->cacheKey()); // every press recalculates with the current settings
        $this->generated = true;
    }

    private function availableShops()
    {
        return ShopAccess::restrict(DB::table('shops'), 'id')->orderBy('name')->get(['id', 'name']);
    }

    /** Selected shops intersected with the viewer's scope (never trust client ids). */
    private function effectiveShopIds(array $selected): array
    {
        $allowed = $this->availableShops()->pluck('id')->map(fn ($id) => (int) $id)->all();

        return $selected === [] ? $allowed : array_values(array_intersect($selected, $allowed));
    }

    private function cacheKey(): string
    {
        return 'security.warnings.'.ShopAccess::salesKey('w', $this->applied['from'].'.'.$this->applied['to'], $this->effectiveShopIds($this->applied['shops'] ?? []));
    }

    public function render()
    {
        $report = null;

        if ($this->generated && $this->applied !== []) {
            $shopIds = $this->effectiveShopIds($this->applied['shops']);
            $from = Carbon::parse($this->applied['from']);
            $to = Carbon::parse($this->applied['to']);

            $report = Cache::remember($this->cacheKey(), (int) config('security.cache_seconds'), fn () => app(SecurityWarnings::class)->build($from, $to, $shopIds));

            $report['warnings'] = array_values(array_filter(
                $report['warnings'],
                fn ($w) => ($this->severity === 'all' || $w['severity'] === $this->severity) && ($this->type === 'all' || $w['type'] === $this->type)
            ));
        }

        return view('livewire.security.warnings', [
            'shops' => $this->availableShops(),
            'noShops' => ShopAccess::hasNone(),
            'report' => $report,
            'types' => SecurityWarnings::typeLabels(),
        ]);
    }
}
