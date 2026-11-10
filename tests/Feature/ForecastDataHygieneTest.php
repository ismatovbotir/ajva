<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\ItemOrderRule;
use App\Models\Pos;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\Shop;
use App\Models\Stock;
use App\Services\ForecastService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Forecast data hygiene: refunds are netted per day and clamped at 0 (inventory.refund_netting) and shop-days with a silent
 * receipt feed are missing data, not zero demand (inventory.feed_aware_censoring). 2026-10-07 is a Wednesday.
 */
class ForecastDataHygieneTest extends TestCase
{
    use RefreshDatabase;

    private Shop $a;

    private Pos $pos;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-07 12:00:00');
        config(['inventory.safety_stock' => false]);
        $this->a = Shop::factory()->create(['name' => 'Alpha']);
        $this->pos = Pos::factory()->create(['shop_id' => $this->a->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function item(string $name, float $stock = 12): Item
    {
        $item = Item::factory()->create(['name' => $name]);
        Stock::query()->create(['shop_id' => $this->a->id, 'item_id' => $item->id, 'qty' => $stock, 'stock_date' => '2026-09-01']);
        Stock::query()->create(['shop_id' => $this->a->id, 'item_id' => $item->id, 'qty' => $stock, 'stock_date' => '2026-10-07']);
        ItemOrderRule::query()->create(['shop_id' => $this->a->id, 'item_id' => $item->id, 'min' => 5, 'max' => 200]);

        return $item;
    }

    private function line(Item $item, float $qty, string $at, bool $sell = true): void
    {
        $receipt = Receipt::factory()->create(['shop_id' => $this->a->id, 'pos_id' => $this->pos->id, 'created_at' => $at, 'sell' => $sell]);
        ReceiptItem::factory()->create(['receipt_id' => $receipt->id, 'item_id' => $item->id, 'qty' => $qty, 'total' => $qty * 10]);
    }

    private function dayAt(int $daysAgo): string
    {
        return Carbon::now()->subDays($daysAgo)->setTime(11, 0)->toDateTimeString();
    }

    /** @return array<string, mixed> */
    private function row(string $name): array
    {
        return collect(app(ForecastService::class)->build()['rows'])->first(fn ($r) => $r['item'] === $name);
    }

    // ---------------------------------------------------------------- refunds

    public function test_a_refund_of_goods_sold_earlier_does_not_push_the_day_or_the_stock_estimate(): void
    {
        $x = $this->item('Refunded', 20);
        for ($i = 1; $i <= 28; $i++) {
            $this->line($x, 5, $this->dayAt($i));
        }
        // Today: nothing sold yet, 8 pieces come back (they were sold on an earlier day).
        $this->line($x, 8, '2026-10-07 09:00:00', false);

        config(['inventory.refund_netting' => 'legacy']);
        $legacy = $this->row('Refunded');
        config(['inventory.refund_netting' => 'same_day']);
        $same = $this->row('Refunded');

        $this->assertEqualsWithDelta(28.0, $legacy['est'], 1e-9, 'legacy: net sold today = -8, the estimate rises by 8');
        $this->assertEqualsWithDelta(20.0, $same['est'], 1e-9, 'same_day: the day is clamped at 0');
        $this->assertEqualsWithDelta(5.0, $same['speed'], 1e-9);
    }

    public function test_a_refund_with_a_negative_quantity_is_taken_as_its_absolute_value(): void
    {
        $x = $this->item('Negative refund');
        for ($i = 1; $i <= 28; $i++) {
            $this->line($x, 5, $this->dayAt($i));
        }
        // Yesterday a refund line whose sign is already flipped (-4): legacy turns it into 4 extra sales (net 9).
        $this->line($x, -4, $this->dayAt(1), false);

        config(['inventory.refund_netting' => 'legacy']);
        $legacy = $this->row('Negative refund');
        config(['inventory.refund_netting' => 'same_day']);
        $same = $this->row('Negative refund');

        $this->assertEqualsWithDelta((5 * 6 + 9) / 7, $legacy['speed'], 0.01);   // 5.571
        $this->assertEqualsWithDelta((5 * 6 + 1) / 7, $same['speed'], 0.01);     // 4.429: 5 sold - |-4| refunded on that day
    }

    public function test_a_refund_never_makes_a_day_negative_and_same_day_refunds_still_net(): void
    {
        $x = $this->item('Same day');
        for ($i = 1; $i <= 28; $i++) {
            $this->line($x, 6, $this->dayAt($i));
        }
        $this->line($x, 2, $this->dayAt(1), false);    // 2 of the 6 sold that day come back
        $this->line($x, 30, $this->dayAt(2), false);   // a huge refund of older goods on a day with 6 sold

        $row = $this->row('Same day');

        // day 1: 6 - 2 = 4; day 2: 6 - 30 -> 0 (clamped); days 3..7 = 6  -> (4 + 0 + 5 x 6) / 7
        $this->assertEqualsWithDelta((4 + 0 + 30) / 7, $row['speed'], 0.01);
    }

    // ---------------------------------------------------------------- feed-aware censoring

    /** $perDay filler sale receipts for every day i of 1..28 except the silent offsets. */
    private function feed(array $silent, int $perDay = 24): void
    {
        $rows = [];
        for ($i = 1; $i <= 28; $i++) {
            if (in_array($i, $silent, true)) {
                continue;
            }
            $at = Carbon::now()->subDays($i)->setTime(15, 0)->toDateTimeString();
            for ($n = 0; $n < $perDay; $n++) {
                $rows[] = [
                    'pos_id' => $this->pos->id, 'shop_id' => $this->a->id, 'number' => 'F'.(++$this->seq), 'session' => 1,
                    'total' => 10, 'discount' => 0, 'active' => 1, 'sell' => 1, 'created_at' => $at, 'updated_at' => $at,
                ];
            }
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('receipts')->insert($chunk);
        }
    }

    private function sellExcept(Item $x, array $silent, float $qty = 4): void
    {
        for ($i = 1; $i <= 28; $i++) {
            if (! in_array($i, $silent, true)) {
                $this->line($x, $qty, $this->dayAt($i));
            }
        }
    }

    public function test_a_silent_feed_day_is_missing_data_not_zero_demand(): void
    {
        $x = $this->item('Steady');
        $this->feed([1]);                 // yesterday: no receipts at all although the shop normally has ~25
        $this->sellExcept($x, [1]);

        $on = $this->row('Steady');
        config(['inventory.feed_aware_censoring' => false]);
        $off = $this->row('Steady');

        $this->assertSame(1, $on['silent_days']);
        $this->assertEqualsWithDelta(4.0, $on['speed'], 1e-9, 'the silent day is left out of the 7-day mean');
        $this->assertSame(0, $off['silent_days']);
        $this->assertEqualsWithDelta(24 / 7, $off['speed'], 0.01, 'legacy: the silent day counts as 0 -> 3.43');
        $this->assertSame($on['usable_days'] + 1, $off['usable_days']);
    }

    public function test_a_normal_quiet_day_is_not_silent(): void
    {
        $x = $this->item('Quiet');
        $this->feed([1]);
        // Yesterday only 6 sale receipts of ~25 (24 %): above the 20 % cut, so it is still demand.
        $at = Carbon::now()->subDay()->setTime(15, 0)->toDateTimeString();
        for ($n = 0; $n < 5; $n++) {
            DB::table('receipts')->insert(['pos_id' => $this->pos->id, 'shop_id' => $this->a->id, 'number' => 'Q'.$n, 'session' => 1, 'total' => 10, 'discount' => 0, 'active' => 1, 'sell' => 1, 'created_at' => $at, 'updated_at' => $at]);
        }
        $this->sellExcept($x, []);

        $this->assertSame(0, $this->row('Quiet')['silent_days']);
    }

    public function test_a_small_shop_is_never_judged_silent(): void
    {
        $x = $this->item('Small');
        $this->feed([1], 5);                  // about 6 receipts a day: the usual day is under 20
        $this->sellExcept($x, [1]);

        $this->assertSame(0, $this->row('Small')['silent_days']);
    }

    public function test_more_than_a_quarter_of_the_window_silent_lowers_the_confidence_one_step(): void
    {
        $x = $this->item('Many gaps');
        $silent = [1, 8, 2, 9, 3, 10, 4, 11];   // two Tuesdays, Mondays, Sundays and Saturdays = 8 of 28 days (28.6 %)
        $this->feed($silent);
        $this->sellExcept($x, $silent);

        $on = $this->row('Many gaps');
        config(['inventory.feed_aware_censoring' => false]);
        $off = $this->row('Many gaps');

        $this->assertSame(8, $on['silent_days']);
        $this->assertSame('high', $off['confidence']);
        $this->assertSame('ok', $on['confidence'], 'high -> ok');
    }

    public function test_one_silent_day_does_not_change_the_confidence(): void
    {
        $x = $this->item('One gap');
        $this->feed([1]);
        $this->sellExcept($x, [1]);

        $this->assertSame('high', $this->row('One gap')['confidence']);
    }
}
