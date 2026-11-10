<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\OrderSyncStatus;
use App\Models\Item;
use App\Models\ItemOrderRule;
use App\Models\Order;
use App\Models\Pos;
use App\Models\Receipt;
use App\Models\Shop;
use App\Models\Stock;
use App\Models\User;
use App\Services\AttentionItems;
use App\Services\DataHealth;
use App\Support\ShopAccess;
use App\Support\TaskRuns;
use App\Support\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** "Now" is 2026-10-07 12:00; every source against hand-made data, per role and scope. */
class AttentionItemsTest extends TestCase
{
    use RefreshDatabase;

    private Shop $alpha;

    private Shop $beta;

    private User $admin;

    private User $aziz;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        Carbon::setTestNow('2026-10-07 12:00:00');
        $this->alpha = Shop::factory()->create(['name' => 'Alpha']);
        $this->beta = Shop::factory()->create(['name' => 'Beta']);
        $this->admin = User::factory()->create();
        $this->aziz = User::factory()->operator()->create(['name' => 'Aziz']);
        $this->aziz->shops()->attach($this->alpha->id);

        // A healthy background by default, so each test sees only its own rows.
        TaskRuns::record(TaskRuns::HEARTBEAT, 'ok', 'pending=0');
        Cache::put('attention.security.high.2026-10-07', 0, 600);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function rows(User $user): array
    {
        return collect(app(AttentionItems::class)->for($user))->keyBy('key')->all();
    }

    private function order(Shop $shop, OrderStatus $status, array $attrs = [], ?float $ageHours = null): Order
    {
        $order = Order::query()->create(['shop_id' => $shop->id, 'status' => $status] + $attrs);
        if ($ageHours !== null) {
            $at = now()->subMinutes((int) ($ageHours * 60))->toDateTimeString();
            DB::table('orders')->where('id', $order->id)->update(['created_at' => $at, 'updated_at' => $at]);
        }

        return $order;
    }

    private function pair(Shop $shop, float $qty, float $min = 5, string $date = '2026-10-07'): Item
    {
        $item = Item::factory()->create();
        Stock::factory()->create(['shop_id' => $shop->id, 'item_id' => $item->id, 'qty' => $qty, 'stock_date' => $date]);
        ItemOrderRule::query()->create(['shop_id' => $shop->id, 'item_id' => $item->id, 'min' => $min, 'max' => $min * 2]);

        return $item;
    }

    /** @return array<int, Receipt> */
    private function receipts(Shop $shop, int $count, float $total, string $at, array $attrs = []): array
    {
        $pos = Pos::query()->where('shop_id', $shop->id)->first() ?? Pos::factory()->create(['shop_id' => $shop->id]);

        return Receipt::factory()->count($count)->create(['shop_id' => $shop->id, 'pos_id' => $pos->id, 'total' => $total, 'created_at' => $at] + $attrs)->all();
    }

    public function test_nothing_wrong_means_no_rows_for_every_role(): void
    {
        $this->assertSame([], app(AttentionItems::class)->for($this->admin));
        $this->assertSame([], app(AttentionItems::class)->for($this->aziz));
        $this->assertSame([], app(AttentionItems::class)->for(User::factory()->monitor()->create()));
        $this->assertSame('12:00', app(AttentionItems::class)->report($this->admin)['checked_at']);
    }

    public function test_drafts_turn_amber_after_two_hours_and_red_after_a_day(): void
    {
        $this->order($this->alpha, OrderStatus::Draft, [], 1);
        $this->assertArrayNotHasKey('drafts', $this->rows($this->admin));

        Cache::flush();
        Cache::put('attention.security.high.2026-10-07', 0, 600);
        $this->order($this->alpha, OrderStatus::Draft, [], 3);
        $row = $this->rows($this->admin)['drafts'];
        $this->assertSame('amber', $row['severity']);
        $this->assertSame(1, $row['count']);

        Cache::flush();
        Cache::put('attention.security.high.2026-10-07', 0, 600);
        $this->order($this->beta, OrderStatus::Draft, [], 30);
        $row = $this->rows($this->admin)['drafts'];
        $this->assertSame('red', $row['severity']);
        $this->assertSame(2, $row['count']);
        $this->assertSame('clipboard', $row['icon']);
        $this->assertStringContainsString('type=draft', $row['url']);
        $this->assertStringContainsString('2 order drafts', $row['text']);
    }

    public function test_an_operator_only_gets_the_rows_of_his_own_shops(): void
    {
        $this->order($this->beta, OrderStatus::Draft, [], 30);
        $this->assertArrayNotHasKey('drafts', $this->rows($this->aziz));
        $this->assertArrayHasKey('drafts', $this->rows($this->admin));

        $this->order($this->alpha, OrderStatus::Draft, [], 3);
        Cache::forget(ShopAccess::salesKey('attention', 'operator.noprofit.2026-10-07', [$this->alpha->id]));
        $row = $this->rows($this->aziz)['drafts'];
        $this->assertSame(1, $row['count']);
        $this->assertSame('amber', $row['severity']);
    }

    public function test_admin_only_sources_never_reach_an_operator(): void
    {
        $this->order($this->alpha, OrderStatus::PendingApproval, ['review_status' => Order::REVIEW_PENDING], 30);
        $this->order($this->alpha, OrderStatus::Confirmed, ['confirmed_at' => '2026-10-07 11:00:00', 'sync_status' => OrderSyncStatus::Failed, 'received_at' => now()]);
        $this->order($this->alpha, OrderStatus::Draft, ['telegram_status' => 'failed'], 1);
        DB::table('failed_jobs')->insert(['uuid' => 'u-1', 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'boom', 'failed_at' => now()]);
        $this->mock(DataHealth::class, fn ($m) => $m->shouldReceive('cached')->andReturn(['silent_count' => 2]));
        Cache::put('attention.security.high.2026-10-07', 4, 600);

        $admin = $this->rows($this->admin);
        foreach (['approval', 'sync_failed', 'telegram', 'failed_jobs', 'silent_shops', 'security'] as $key) {
            $this->assertArrayHasKey($key, $admin, $key);
        }
        $this->assertSame('red', $admin['approval']['severity']);
        $this->assertSame('red', $admin['sync_failed']['severity']);
        $this->assertSame('amber', $admin['telegram']['severity']);
        $this->assertSame('amber', $admin['failed_jobs']['severity']);
        $this->assertSame(2, $admin['silent_shops']['count']);
        $this->assertSame(4, $admin['security']['count']);

        $this->assertSame([], array_keys($this->rows($this->aziz)));
    }

    public function test_confirmed_orders_not_received_by_the_deadline_are_red(): void
    {
        $this->order($this->alpha, OrderStatus::Confirmed, ['confirmed_at' => '2026-10-07 09:00:00', 'sync_status' => OrderSyncStatus::Synced]);   // on the way
        $this->assertArrayNotHasKey('receipt_overdue', $this->rows($this->admin));

        Cache::flush();
        Cache::put('attention.security.high.2026-10-07', 0, 600);
        $this->order($this->alpha, OrderStatus::Confirmed, ['confirmed_at' => '2026-10-06 10:00:00', 'sync_status' => OrderSyncStatus::Synced]);
        $row = $this->rows($this->aziz)['receipt_overdue'];
        $this->assertSame('red', $row['severity']);
        $this->assertSame(1, $row['count']);
    }

    public function test_stockouts_use_the_explicit_zero_rule_and_turn_red_above_five_percent(): void
    {
        for ($i = 0; $i < 29; $i++) {
            $this->pair($this->alpha, 10);
        }
        $this->pair($this->alpha, 0);                       // 1 of 30 = 3.3 %: amber
        $this->pair($this->alpha, 0, 5, '2026-10-05');      // not reported by the latest sync: unknown, NOT a stock-out
        $row = $this->rows($this->admin)['stockouts'];
        $this->assertSame('amber', $row['severity']);
        $this->assertSame(1, $row['count']);
        $this->assertStringContainsString('3.3%', $row['text']);
        $this->assertSame(1, $this->rows($this->admin)['not_reported']['count']);
        $this->assertStringContainsString('ordering', $row['url']);

        Cache::flush();
        Cache::put('attention.security.high.2026-10-07', 0, 600);
        for ($i = 0; $i < 3; $i++) {
            $this->pair($this->alpha, 0);
        }
        $row = $this->rows($this->admin)['stockouts'];
        $this->assertSame('red', $row['severity']);   // 4 of 33 = 12 %
        $this->assertSame(4, $row['count']);

        // The operator links to the page he can open, and another shop's pairs are not his.
        $this->pair($this->beta, 0);
        Cache::flush();
        Cache::put('attention.security.high.2026-10-07', 0, 600);
        $operatorRow = $this->rows($this->aziz)['stockouts'];
        $this->assertSame(4, $operatorRow['count']);
        $this->assertStringNotContainsString('ordering', $operatorRow['url']);
    }

    public function test_a_sales_drop_needs_a_baseline_of_ten_receipts(): void
    {
        $this->receipts($this->alpha, 5, 100, '2026-10-06 10:00:00');   // yesterday up to now: only 5 receipts
        $this->receipts($this->alpha, 5, 40, '2026-10-07 10:00:00');   // today: 200
        $this->assertArrayNotHasKey('sales_drop', $this->rows($this->admin));

        Cache::flush();
        Cache::put('attention.security.high.2026-10-07', 0, 600);
        $this->receipts($this->alpha, 5, 100, '2026-10-06 09:00:00');   // now 10 receipts / 1000 yesterday against 200 today
        $row = $this->rows($this->admin)['sales_drop'];
        $this->assertSame('red', $row['severity']);
        $this->assertStringContainsString('below yesterday', $row['text']);
        $this->assertSame(80, $row['count']);
    }

    public function test_refund_and_cancelled_spikes_need_ten_receipts_today(): void
    {
        $this->receipts($this->alpha, 20, 100, '2026-10-07 10:00:00');
        $this->receipts($this->alpha, 2, 100, '2026-10-07 10:30:00', ['sell' => false]);     // 200 of 2000 = 10 %
        $this->receipts($this->alpha, 2, 100, '2026-10-07 10:45:00', ['active' => false]);   // 2 of 24 = 8.3 %

        $rows = $this->rows($this->aziz);
        $this->assertSame('red', $rows['refunds']['severity']);
        $this->assertSame('red', $rows['cancelled']['severity']);
        $this->assertSame(2, $rows['refunds']['count']);

        // A shop with no receipts of its own never inherits another shop's rates.
        $other = User::factory()->operator()->create();
        $other->shops()->attach($this->beta->id);
        $this->assertArrayNotHasKey('refunds', $this->rows($other));
    }

    public function test_the_warehouse_not_synced_is_red_for_the_admin(): void
    {
        Warehouse::setShopId($this->beta->id);
        $this->pair($this->alpha, 5);                         // another shop synced on the 7th
        $this->pair($this->beta, 5, 5, '2026-10-05');         // the warehouse did not

        $row = $this->rows($this->admin)['wh_not_synced'];
        $this->assertSame('red', $row['severity']);
        $this->assertStringContainsString('2026-10-05', $row['text']);
        $this->assertArrayNotHasKey('wh_not_synced', $this->rows($this->aziz));
    }

    public function test_the_scheduler_and_the_queue_are_watched(): void
    {
        DB::table('task_runs')->delete();
        $this->assertSame('red', $this->rows($this->admin)['scheduler']['severity']);

        Cache::flush();
        Cache::put('attention.security.high.2026-10-07', 0, 600);
        TaskRuns::record(TaskRuns::HEARTBEAT, 'ok');
        DB::table('task_runs')->update(['finished_at' => now()->subMinutes(5)]);
        $this->assertStringContainsString('more than 3 minutes', $this->rows($this->admin)['scheduler']['text']);

        Cache::flush();
        Cache::put('attention.security.high.2026-10-07', 0, 600);
        DB::table('task_runs')->update(['finished_at' => now()]);
        $this->assertArrayNotHasKey('scheduler', $this->rows($this->admin));

        config(['queue.default' => 'database']);
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->subMinutes(5)->timestamp, 'created_at' => now()->subMinutes(5)->timestamp]);
        Cache::flush();
        Cache::put('attention.security.high.2026-10-07', 0, 600);
        $this->assertArrayHasKey('queue', $this->rows($this->admin));
    }

    public function test_at_most_eight_rows_most_severe_first(): void
    {
        DB::table('task_runs')->delete();                                                                    // scheduler (red)
        Cache::put('attention.security.high.2026-10-07', 3, 600);                                             // security (red)
        $this->mock(DataHealth::class, fn ($m) => $m->shouldReceive('cached')->andReturn(['silent_count' => 1]));   // silent (red)
        DB::table('failed_jobs')->insert(['uuid' => 'u-2', 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'boom', 'failed_at' => now()]);   // amber
        $this->order($this->alpha, OrderStatus::Draft, ['telegram_status' => 'failed'], 3);                    // drafts (amber) + telegram (amber)
        $this->order($this->alpha, OrderStatus::Confirmed, ['confirmed_at' => '2026-10-07 11:00:00', 'sync_status' => OrderSyncStatus::Failed]);   // sync (red)
        $this->order($this->alpha, OrderStatus::Confirmed, ['confirmed_at' => '2026-10-06 10:00:00', 'sync_status' => OrderSyncStatus::Synced]);  // overdue (red)
        $this->order($this->alpha, OrderStatus::PendingApproval, ['review_status' => Order::REVIEW_PENDING], 30);                       // approval (red)
        $this->pair($this->alpha, 0);                                                                          // stockouts (red)
        $this->pair($this->alpha, 3, 5, '2026-10-05');                                                         // not reported (amber)

        $rows = app(AttentionItems::class)->for($this->admin);

        $this->assertCount(8, $rows);
        $severities = array_column($rows, 'severity');
        $sorted = $severities;
        usort($sorted, fn ($a, $b) => ($a === 'red' ? 0 : 1) <=> ($b === 'red' ? 0 : 1));
        $this->assertSame($sorted, $severities, 'red rows come before amber rows');
        $this->assertSame(['red', 'red', 'red', 'red', 'red', 'red', 'red'], array_slice($severities, 0, 7));
        foreach ($rows as $row) {
            $this->assertEqualsCanonicalizing(['key', 'severity', 'icon', 'text', 'count', 'url'], array_keys($row));
        }
    }

    public function test_the_result_is_cached_per_scope_role_and_profit_variant_and_sales_version(): void
    {
        $this->order($this->alpha, OrderStatus::Draft, [], 30);
        app(AttentionItems::class)->for($this->aziz);

        $key = fn (string $role, string $profit, ?array $ids) => ShopAccess::salesKey('attention', "{$role}.{$profit}.2026-10-07", $ids);
        $this->assertTrue(Cache::has($key('operator', 'noprofit', [$this->alpha->id])));
        $this->assertFalse(Cache::has($key('operator', 'noprofit', [$this->beta->id])));
        $this->assertFalse(Cache::has($key('operator', 'profit', [$this->alpha->id])));

        // A second operator of the same shops with the profit flag gets his own entry, never the other's.
        $rich = User::factory()->operator()->withProfit()->create();
        $rich->shops()->attach($this->alpha->id);
        app(AttentionItems::class)->for($rich);
        $this->assertTrue(Cache::has($key('operator', 'profit', [$this->alpha->id])));

        app(AttentionItems::class)->for($this->admin);
        $this->assertTrue(Cache::has($key('admin', 'profit', null)));

        // Within the TTL a new draft is not seen; a sales version bump (receipt ingestion) drops every scope at once.
        $this->order($this->alpha, OrderStatus::Draft, [], 30);
        $this->assertSame(1, $this->rows($this->aziz)['drafts']['count']);
        ShopAccess::bumpSalesVersion();
        $this->assertSame(2, $this->rows($this->aziz)['drafts']['count']);
    }

    public function test_an_operator_without_shops_gets_nothing(): void
    {
        $this->order($this->alpha, OrderStatus::Draft, [], 30);

        $this->assertSame([], app(AttentionItems::class)->for(User::factory()->operator()->create()));
    }
}
