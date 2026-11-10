<?php

namespace App\Livewire;

use App\Models\Barcode;
use App\Models\Item;
use App\Models\Order;
use App\Models\Receipt;
use App\Models\Shop;
use App\Support\Navigation;
use App\Support\ShopAccess;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * Global search (Ctrl+K or "/", the search buttons of the top bars): items, orders, receipts, shops and menu pages in
 * one box, at most 5 per group, results are plain links (no side effects). Needs MIN_CHARS characters. Everything
 * shop-bound goes through ShopAccess (an operator never finds another shop's order, receipt or shop) and the pages
 * come from App\Support\Navigation for the user's own role (an operator never gets an admin page). Items are global
 * by design; only code, GTIN and name are searched and shown, never a cost or price.
 */
class GlobalSearch extends Component
{
    public const MIN_CHARS = 3;

    public const PER_GROUP = 5;

    public string $query = '';

    public function boot(): void
    {
        // Livewire updates skip the route middleware: a monitor-only account has nothing to search.
        abort_unless(in_array(auth()->user()?->role?->value, ['admin', 'operator'], true), 403);
    }

    public function render()
    {
        $term = trim($this->query);
        $groups = mb_strlen($term) >= self::MIN_CHARS ? $this->search($term) : [];

        return view('livewire.global-search', [
            'groups' => $groups,
            'hasQuery' => mb_strlen($term) >= self::MIN_CHARS,
            'minChars' => self::MIN_CHARS,
        ]);
    }

    /**
     * @return array<int, array{key: string, label: string, rows: array<int, array{title: string, sub: string, url: string}>}>
     */
    private function search(string $term): array
    {
        $groups = [
            ['key' => 'pages', 'label' => __('Pages'), 'rows' => $this->pages($term)],
            ['key' => 'items', 'label' => __('Items'), 'rows' => $this->items($term)],
            ['key' => 'orders', 'label' => __('Orders'), 'rows' => $this->orders($term)],
            ['key' => 'receipts', 'label' => __('Receipts'), 'rows' => $this->receipts($term)],
            ['key' => 'shops', 'label' => __('Shops'), 'rows' => $this->shops($term)],
        ];

        return array_values(array_filter($groups, fn (array $g) => $g['rows'] !== []));
    }

    /** Menu pages the user may open, matched by (translated or English) label: contains, or the letters in order. */
    private function pages(string $term): array
    {
        $needle = mb_strtolower($term);
        $matches = fn (string $label) => str_contains($label, $needle) || $this->subsequence($needle, $label);
        $rows = [];

        foreach (Navigation::groups(auth()->user()->role->value) as $group) {
            foreach ($group['items'] as $item) {
                $label = __($item['label']);
                if ($matches(mb_strtolower($label)) || $matches(mb_strtolower($item['label']))) {
                    $rows[] = ['title' => $label, 'sub' => $group['single'] ?? false ? '' : __($group['label']), 'url' => route($item['route'])];
                }
            }
        }

        return array_slice($rows, 0, self::PER_GROUP);
    }

    private function subsequence(string $needle, string $haystack): bool
    {
        $i = 0;
        $len = mb_strlen($needle);
        foreach (mb_str_split($haystack) as $ch) {
            if ($i < $len && $ch === mb_substr($needle, $i, 1)) {
                $i++;
            }
        }

        return $i === $len;
    }

    /** Code (mark / 1C id), GTIN prefix or name; the same matching as the draft add-item search. */
    private function items(string $term): array
    {
        $escaped = addcslashes($term, '%_\\');
        $like = '%'.$escaped.'%';

        return Item::query()
            ->where(function ($q) use ($term, $like, $escaped) {
                $q->where('name', 'like', $like)
                    ->orWhere('mark', 'like', $like)
                    ->orWhereIn('id', Barcode::query()->where('gtin', 'like', $escaped.'%')->select('item_id'));
                if (ctype_digit($term)) {
                    $q->orWhere('id', (int) $term);
                }
            })
            ->orderBy('name')->limit(self::PER_GROUP)->get(['id', 'name', 'mark'])
            ->map(fn (Item $i) => ['title' => (string) $i->name, 'sub' => (string) ($i->mark ?? '#'.$i->id), 'url' => route('items.show', $i->id)])
            ->all();
    }

    /** "ORD-000123" or a plain id, only orders of the user's shops. */
    private function orders(string $term): array
    {
        if (preg_match('/^(?:ord-?)?0*(\d+)$/i', $term, $m) !== 1) {
            return [];
        }

        /** @var Collection<int, Order> $orders */
        $orders = ShopAccess::restrict(Order::query(), 'orders.shop_id')->with('shop:id,name')
            ->where('id', (int) $m[1])->limit(self::PER_GROUP)->get();

        return $orders->map(fn (Order $o) => [
            'title' => $o->number(),
            'sub' => trim(($o->shop?->name ?? '').' · '.($o->status?->label() ?? ''), ' ·'),
            'url' => route('orders.show', $o),
        ])->all();
    }

    /** Receipt number (exact or prefix), only receipts of the user's shops. */
    private function receipts(string $term): array
    {
        $escaped = addcslashes($term, '%_\\');

        return ShopAccess::restrict(Receipt::query(), 'receipts.shop_id')->with('shop:id,name')
            ->where('number', 'like', $escaped.'%')
            ->latest('id')->limit(self::PER_GROUP)->get()
            ->map(fn (Receipt $r) => [
                'title' => __('Receipt').' '.$r->number,
                'sub' => trim(($r->shop?->name ?? '').' · '.($r->created_at?->format('d.m.Y H:i') ?? ''), ' ·'),
                'url' => route('receipts.show', $r),
            ])->all();
    }

    private function shops(string $term): array
    {
        $like = '%'.addcslashes($term, '%_\\').'%';

        return ShopAccess::restrict(Shop::query(), 'shops.id')
            ->where('name', 'like', $like)->orderBy('name')->limit(self::PER_GROUP)->get(['id', 'name'])
            ->map(fn (Shop $s) => ['title' => (string) $s->name, 'sub' => '#'.$s->id, 'url' => route('shops.show', $s->id)])
            ->all();
    }
}
