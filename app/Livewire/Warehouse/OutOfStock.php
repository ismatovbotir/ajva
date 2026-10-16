<?php

namespace App\Livewire\Warehouse;

use App\Services\WarehouseOutOfStock;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Items out of stock at the main warehouse (ADMIN only). Definition and figures: App\Services\WarehouseOutOfStock.
 * The heavy part is cached there; the filters, sort and paging here run on the cached rows.
 */
#[Layout('components.layouts.app', ['title' => 'Out of stock (main warehouse)'])]
class OutOfStock extends Component
{
    use WithPagination;

    private const PER_PAGE = 50;

    #[Url]
    public string $group = '';

    #[Url]
    public string $abc = '';

    #[Url]
    public string $reason = '';

    #[Url]
    public bool $needed = true;

    #[Url(as: 'min_days')]
    public string $minDays = '';

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $sort = 'priority';

    #[Url]
    public string $dir = 'desc';

    /** false until Generate is pressed: opening the page reads and calculates nothing. */
    public bool $generated = false;

    /** Every press recalculates (the cached result of the last version is dropped first). */
    public function generate(): void
    {
        app(WarehouseOutOfStock::class)->forget();
        $this->generated = true;
        $this->resetPage();
    }

    public function boot(): void
    {
        // Livewire updates skip the route middleware: re-check on every request.
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    public function updated(string $name): void
    {
        if ($name !== 'page') {
            $this->resetPage();
        }
    }

    public function sortBy(string $column): void
    {
        if (! in_array($column, WarehouseOutOfStock::SORTS, true)) {
            return;
        }
        $this->dir = $this->sort === $column && $this->dir === 'desc' ? 'asc' : 'desc';
        $this->sort = $column;
        $this->resetPage();
    }

    /** @return array<string, mixed> */
    private function query(): array
    {
        return [
            'group' => $this->group, 'abc' => $this->abc, 'reason' => $this->reason, 'needed' => $this->needed,
            'min_days' => $this->minDays, 'q' => $this->search, 'sort' => $this->sort, 'dir' => $this->dir,
        ];
    }

    public function render()
    {
        $service = app(WarehouseOutOfStock::class);
        $data = $this->generated
            ? $service->data()
            : ['state' => 'pending', 'partial' => false, 'kpi' => null, 'rows' => [], 'ref' => null, 'chain' => null, 'coverage' => null, 'histogram' => []];
        $filters = WarehouseOutOfStock::filters($this->query());
        $rows = $service->apply($data['rows'], $filters);

        $page = $this->getPage();
        $paginator = new LengthAwarePaginator(array_slice($rows, ($page - 1) * self::PER_PAGE, self::PER_PAGE), count($rows), self::PER_PAGE, $page, ['path' => request()->url()]);

        $link = array_filter(
            ['group' => $filters['group'], 'abc' => $filters['abc'], 'reason' => $filters['reason'], 'needed' => (int) $filters['needed'], 'min_days' => $filters['min_days'] ?: null, 'q' => $filters['q'], 'sort' => $filters['sort'], 'dir' => $filters['dir']],
            fn ($v) => $v !== null && $v !== '',
        );

        return view('livewire.warehouse.out-of-stock', [
            'data' => $data,
            'generated' => $this->generated,
            'rows' => $paginator,
            'sort' => $filters['sort'],
            'dir' => $filters['dir'],
            'csvUrl' => route('warehouse.out-of-stock.csv', $link),
            'printUrl' => route('warehouse.out-of-stock.print', $link),
        ]);
    }
}
