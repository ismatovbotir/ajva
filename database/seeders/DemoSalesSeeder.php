<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Group;
use App\Models\Item;
use App\Models\ItemOrderRule;
use App\Models\Pos;
use App\Models\Shop;
use App\Models\Stock;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Local demo data so the dashboard / receipts / analytics screens have
 * something to show. Run explicitly:
 *
 *     php artisan db:seed --class=DemoSalesSeeder
 *
 * Safe to re-run: shops, items, stock and rules are matched by name, and the
 * demo receipts (numbers starting "DEMO-") are rebuilt around the current time.
 */
class DemoSalesSeeder extends Seeder
{
    private const SHOPS = ['Chilonzor', 'Yunusobod', 'Mirobod', 'Sergeli'];

    private const CASHIERS = ['Aziza K.', 'Bobur T.', 'Dilnoza R.', 'Jasur M.'];

    private const PAYMENTS = ['Naqd', 'Uzcard', 'Humo', 'Payme'];

    /** group => [item name => price] */
    private const CATALOG = [
        'Oziq-ovqat' => ['Non' => 4000, 'Sut 1L' => 12000, 'Tuxum 10 dona' => 18000, 'Yogurt' => 8000, 'Pishloq' => 45000],
        'Ichimliklar' => ['Suv 1.5L' => 5000, 'Cola 1L' => 11000, 'Sok 1L' => 14000, 'Choy 100g' => 16000],
        'Maishiy' => ['Sovun' => 7000, 'Shampun' => 38000, 'Tish pastasi' => 22000],
    ];

    public function run(): void
    {
        // One transaction: thousands of single-row inserts are very slow on
        // SQLite otherwise (an fsync per statement).
        DB::transaction(fn () => $this->seed());

        $this->command?->info('Demo data ready: '.DB::table('receipts')->where('number', 'like', 'DEMO-%')->count().' demo receipts.');
    }

    private function seed(): void
    {
        mt_srand(42);

        $shops = collect(self::SHOPS)->map(fn ($name) => Shop::query()->firstOrCreate(['name' => $name]));

        $items = [];
        foreach (self::CATALOG as $groupName => $products) {
            $group = Group::query()->firstOrCreate(['name' => $groupName]);
            $category = Category::query()->firstOrCreate(['name' => $groupName]);
            foreach ($products as $name => $price) {
                $item = Item::query()->firstOrCreate(
                    ['name' => $name],
                    ['group_id' => $group->id, 'category_id' => $category->id, 'mark' => 'D'.str_pad((string) (count($items) + 1), 3, '0', STR_PAD_LEFT)]
                );
                $items[] = ['model' => $item, 'price' => $price];
            }
        }

        $posByShop = [];
        foreach ($shops as $shop) {
            foreach ([1, 2] as $n) {
                $posByShop[$shop->id][] = Pos::query()->firstOrCreate(['name' => "kassa {$shop->id}-{$n}", 'shop_id' => $shop->id]);
            }
            foreach ($items as $entry) {
                Stock::query()->updateOrCreate(
                    ['shop_id' => $shop->id, 'item_id' => $entry['model']->id],
                    ['qty' => mt_rand(20, 200)]
                );
                if (mt_rand(1, 10) <= 8) {
                    $min = mt_rand(10, 40);
                    ItemOrderRule::query()->updateOrCreate(
                        ['shop_id' => $shop->id, 'item_id' => $entry['model']->id],
                        ['min' => $min, 'max' => $min + mt_rand(60, 150)]
                    );
                }
            }
        }

        $this->rebuildReceipts($shops, $posByShop, $items);
    }

    private function rebuildReceipts($shops, array $posByShop, array $items): void
    {
        $oldIds = DB::table('receipts')->where('number', 'like', 'DEMO-%')->pluck('id');
        foreach ($oldIds->chunk(500) as $chunk) {
            DB::table('receipt_items')->whereIn('receipt_id', $chunk)->delete();
            DB::table('receipt_payments')->whereIn('receipt_id', $chunk)->delete();
            DB::table('receipts')->whereIn('id', $chunk)->delete();
        }

        $now = now();
        $today = $now->copy()->startOfDay();
        $yesterday = $today->copy()->subDay();
        $seq = 0;

        foreach ($shops as $shopIndex => $shop) {
            // Busier shops first so the colours / ranking differ.
            $scale = 1.4 - $shopIndex * 0.25;

            foreach ([[$yesterday, 21], [$today, max(9, min(21, $now->hour))]] as [$day, $lastHour]) {
                for ($hour = 8; $hour <= $lastHour; $hour++) {
                    $peak = in_array($hour, [12, 13, 18, 19], true) ? 2.2 : 1.0;
                    $count = (int) round((mt_rand(2, 6)) * $scale * $peak);

                    for ($i = 0; $i < $count; $i++) {
                        $at = $day->copy()->setTime($hour, mt_rand(0, 59), mt_rand(0, 59));
                        if ($at->greaterThan($now) && $day->isSameDay($today)) {
                            continue;
                        }
                        $this->createReceipt($shop, $posByShop[$shop->id][mt_rand(0, 1)], $items, $at, ++$seq);
                    }
                }
            }
        }
    }

    private function createReceipt(Shop $shop, Pos $pos, array $items, Carbon $at, int $seq): void
    {
        $roll = mt_rand(1, 100);
        $sell = $roll > 8;          // ~8% refunds
        $active = $roll <= 8 || $roll > 15; // ~7% cancelled (the 9–15 band)

        $lines = [];
        $total = 0.0;
        foreach ((array) array_rand($items, mt_rand(1, 4)) as $key) {
            $entry = $items[$key];
            $qty = mt_rand(1, 3);
            $lineTotal = $qty * $entry['price'];
            $total += $lineTotal;
            $lines[] = [
                'item_id' => $entry['model']->id,
                'name' => $entry['model']->name,
                'qty' => $qty,
                'price' => $entry['price'],
                'total' => $lineTotal,
                'sum' => $lineTotal,
            ];
        }

        $receiptId = DB::table('receipts')->insertGetId([
            'pos_id' => $pos->id,
            'shop_id' => $shop->id,
            'number' => 'DEMO-'.str_pad((string) $seq, 5, '0', STR_PAD_LEFT),
            'cashier' => self::CASHIERS[mt_rand(0, count(self::CASHIERS) - 1)],
            'total' => $total,
            'gross_total' => $total,
            'discount' => 0,
            'active' => $active,
            'sell' => $sell,
            'status' => $active ? 'success' : 'cancelled',
            'qty_positions' => count($lines),
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        DB::table('receipt_items')->insert(array_map(fn ($l) => $l + [
            'receipt_id' => $receiptId,
            'storno' => false,
            'active' => true,
            'discount' => 0,
            'receipt_active' => $active,
            'receipt_sell' => $sell,
            'created_at' => $at,
            'updated_at' => $at,
        ], $lines));

        // One payment, or a cash + card split on larger receipts.
        $payments = [[self::PAYMENTS[mt_rand(0, 3)], $total]];
        if ($total > 30000 && mt_rand(1, 3) === 1) {
            $half = round($total / 2, 2);
            $payments = [['Naqd', $half], [self::PAYMENTS[mt_rand(1, 3)], $total - $half]];
        }
        DB::table('receipt_payments')->insert(array_map(fn ($p) => [
            'receipt_id' => $receiptId,
            'payment' => $p[0],
            'value' => $p[1],
            'created_at' => $at,
            'updated_at' => $at,
        ], $payments));
    }
}
