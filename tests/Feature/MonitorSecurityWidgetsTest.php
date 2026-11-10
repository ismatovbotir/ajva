<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Pos;
use App\Models\Shop;
use App\Models\User;
use App\Monitor\Grid;
use App\Monitor\LayoutValidator;
use App\Monitor\MonitorData;
use App\Monitor\Presets;
use App\Monitor\WidgetCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsCustomMonitors;
use Tests\TestCase;

/**
 * The Security group of the monitor catalog (SecurityWarnings as widgets): catalog rules, admin-only visibility, no cashier
 * name on a public link, minimum-sample "not enough data" states, one shared dataset built only when a visible widget needs it.
 * Time is frozen at 2026-11-10 12:00 (a Tuesday).
 */
class MonitorSecurityWidgetsTest extends TestCase
{
    use BuildsCustomMonitors;
    use RefreshDatabase;

    private const KEYS = [
        'security_warnings_count', 'security_refund_by_cashier', 'security_cancelled_rate', 'security_discount_by_cashier',
        'security_silent_shops', 'security_off_hours_today', 'security_integrity_flags', 'security_number_gaps_duplicates', 'security_unusual_baskets',
    ];

    private Shop $a;

    private Pos $posA;

    private Pos $posB;

    /** @var array<int, int> */
    private array $seq = [];

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        Carbon::setTestNow('2026-11-10 12:00:00');
        $this->a = Shop::factory()->create(['name' => 'Alpha']);
        $this->posA = Pos::factory()->create(['shop_id' => $this->a->id]);
        $this->posB = Pos::factory()->create(['shop_id' => $this->a->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function rc(Pos $pos, array $o = []): int
    {
        $at = $o['at'] ?? '2026-11-09 12:00:00';
        unset($o['at']);
        $this->seq[$pos->id] = ($this->seq[$pos->id] ?? 0) + 1;

        return DB::table('receipts')->insertGetId($o + [
            'pos_id' => $pos->id, 'shop_id' => $pos->shop_id, 'number' => (string) $this->seq[$pos->id],
            'cashier' => 'Anna', 'total' => 100, 'discount' => 0, 'active' => 1, 'sell' => 1, 'session' => 1,
            'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    private function many(int $n, Pos $pos, array $o = []): void
    {
        for ($i = 0; $i < $n; $i++) {
            $this->rc($pos, $o);
        }
    }

    private function line(int $receiptId, float $qty = 1): void
    {
        DB::table('receipt_items')->insert([
            'receipt_id' => $receiptId, 'item_id' => Item::factory()->create()->id, 'storno' => 0, 'active' => 1,
            'qty' => $qty, 'price' => 10, 'discount' => 0, 'total' => 10 * $qty, 'receipt_active' => 1, 'receipt_sell' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param  array<int, string>  $keys */
    private function build(array $keys, ?User $as = null, bool $public = false, ?array $shops = null): array
    {
        $as ??= User::factory()->create(); // an admin
        $this->actingAs($as);
        $layout = [];
        foreach ($keys as $key) {
            $layout = Grid::add($layout, WidgetCatalog::find($key), (string) Str::uuid());
        }

        return MonitorData::build($this->scopeFor($this->customMonitor([]), $shops ?? [$this->a->id], false, $public), $layout);
    }

    /** Alpha: Anna is normal, Bob refunds a quarter of his sales and gives deep discounts, plus cancelled receipts. */
    private function weekFixture(): void
    {
        $this->many(60, $this->posA);
        $this->many(30, $this->posB, ['cashier' => 'Bob']);
        $this->many(10, $this->posB, ['cashier' => 'Bob', 'sell' => 0]);
        $this->many(12, $this->posB, ['cashier' => 'Bob', 'total' => 60, 'discount' => 40]);
        $this->many(5, $this->posA, ['active' => 0, 'total' => 0]);
    }

    // ---------------------------------------------------------------- catalog

    public function test_the_security_group_is_complete_admin_only_and_never_public_or_cost_bearing(): void
    {
        foreach (self::KEYS as $key) {
            $def = WidgetCatalog::find($key);
            $this->assertNotNull($def, $key);
            $this->assertSame('Security', $def->group, $key);
            $this->assertTrue($def->adminOnly, $key.' admin only');
            $this->assertFalse($def->publicSafe, $key.' not public safe');
            $this->assertFalse($def->profit, $key.' carries no cost');
            $this->assertContains($def->dataset, ['security_week', 'security_today'], $key);
            $this->assertNotNull($def->rule, $key.' states its rule');
        }
        $this->assertContains('Security', array_map(fn ($d) => $d->group, WidgetCatalog::all()));
        $this->assertCount(count(self::KEYS), array_filter(WidgetCatalog::all(), fn ($d) => $d->group === 'Security'));
        $this->assertSame(['kpi', 'gauge'], array_map(fn ($d) => $d->value, WidgetCatalog::find('security_cancelled_rate')->diagrams));

        $security = Presets::layout('security');
        $this->assertSame([], LayoutValidator::validate($security)['errors']);
        $this->assertEqualsCanonicalizing(self::KEYS, array_column($security, 'key'));
    }

    // ---------------------------------------------------------------- the week dataset

    public function test_the_week_widgets_show_flagged_cashiers_with_sample_and_ratio_for_an_admin(): void
    {
        $this->weekFixture();

        $r = $this->build(['security_warnings_count', 'security_refund_by_cashier', 'security_cancelled_rate', 'security_discount_by_cashier']);

        $count = $this->payload($r, 'security_warnings_count');
        $this->assertSame('bad', $count['status'], 'a high warning exists');
        $this->assertGreaterThanOrEqual(1, (int) $count['value']);
        $this->assertStringContainsString('High', $count['note']);
        $this->assertStringContainsString('Last 7 days until 12:00', $count['footer']);

        $refunds = $this->payload($r, 'security_refund_by_cashier');
        $this->assertSame('Bob · Alpha', $refunds['items'][0]['label']);
        $this->assertSame('bad', $refunds['items'][0]['status']);
        $this->assertSame('52', $refunds['items'][0]['cells'][3], 'the sample (receipts of the cashier) is shown');
        $this->assertSame('High', $refunds['items'][0]['cells'][4]);
        $this->assertCount(5, $refunds['columns']);

        $cancelled = $this->payload($r, 'security_cancelled_rate');
        $this->assertSame('warn', $cancelled['status'], '5 of 117 receipts is above 2 % and below 5 %');
        $this->assertStringContainsString('previous 7 days: n/a', $cancelled['note'], 'the baseline has fewer than 20 receipts: no rate');

        $discounts = $this->payload($r, 'security_discount_by_cashier');
        $this->assertSame('Bob · Alpha', $discounts['items'][0]['label']);
    }

    public function test_a_clean_chain_shows_all_clear_rows_not_an_empty_tile(): void
    {
        $this->many(60, $this->posA);
        $this->many(60, $this->posB, ['cashier' => 'Bob']);

        $r = $this->build(['security_refund_by_cashier', 'security_warnings_count']);

        $table = $this->payload($r, 'security_refund_by_cashier');
        $this->assertSame('ok', $table['state']);
        $this->assertSame('ok', $table['items'][0]['status']);
        $this->assertSame('No cashier is above the rest of the chain.', $table['items'][0]['label']);
        $this->assertSame('ok', $this->payload($r, 'security_warnings_count')['status']);
    }

    public function test_too_little_data_says_so_instead_of_showing_a_rate(): void
    {
        $this->many(8, $this->posA);
        $this->many(4, $this->posA, ['active' => 0, 'total' => 0]);

        $r = $this->build(['security_warnings_count', 'security_cancelled_rate', 'security_refund_by_cashier', 'security_discount_by_cashier']);

        foreach (['security_warnings_count', 'security_cancelled_rate'] as $key) {
            $w = $this->payload($r, $key);
            $this->assertSame('—', $w['value'], $key);
            $this->assertSame('none', $w['status'], $key);
            $this->assertStringStartsWith('Not enough data', $w['note'], $key);
        }
        foreach (['security_refund_by_cashier', 'security_discount_by_cashier'] as $key) {
            $w = $this->payload($r, $key);
            $this->assertSame('empty', $w['state'], $key);
            $this->assertStringContainsString('Not enough data', $w['footer'], $key);
        }
    }

    // ---------------------------------------------------------------- today widgets

    public function test_today_widgets_count_gaps_duplicates_integrity_and_unusual_baskets(): void
    {
        $at = '2026-11-10 09:00:00';
        // Till A, shift 1: numbers 1..12 without 3 = one missing number.
        foreach (array_diff(range(1, 12), [3]) as $n) {
            $this->rc($this->posA, ['number' => (string) $n, 'at' => $at]);
        }
        // Till B: the same number and total in two shifts within a minute = a duplicate looking receipt.
        $this->rc($this->posB, ['number' => '5', 'total' => 777, 'session' => 1, 'at' => '2026-11-10 10:00:00']);
        $this->rc($this->posB, ['number' => '5', 'total' => 777, 'session' => 2, 'at' => '2026-11-10 10:00:20']);
        // An impossible sale total.
        $this->rc($this->posB, ['number' => '99', 'total' => 0, 'session' => 3, 'at' => '2026-11-10 10:30:00']);
        // Beta: 40 ordinary receipts and one huge single-item receipt.
        $beta = Shop::factory()->create(['name' => 'Beta']);
        $posC = Pos::factory()->create(['shop_id' => $beta->id]);
        $this->many(40, $posC, ['at' => $at]);
        $this->line($this->rc($posC, ['total' => 100000, 'at' => $at]), 20);

        $r = $this->build(['security_number_gaps_duplicates', 'security_integrity_flags', 'security_unusual_baskets'], shops: [$this->a->id, $beta->id]);

        $gaps = $this->payload($r, 'security_number_gaps_duplicates');
        $this->assertSame('1', $gaps['value']);
        $this->assertSame('warn', $gaps['status']);
        $this->assertStringContainsString('1 duplicate looking receipts', $gaps['note']);

        $integrity = $this->payload($r, 'security_integrity_flags');
        $this->assertSame('1', $integrity['value']);
        $this->assertSame('warn', $integrity['status']);

        $baskets = $this->payload($r, 'security_unusual_baskets');
        $this->assertSame('Beta', $baskets['items'][0]['label']);
        $this->assertSame('bad', $baskets['items'][0]['status']);
        $this->assertSame('High', $baskets['items'][0]['cells'][3]);
        $this->assertSame(['security_today'], $r['datasets'], 'one shared today dataset for all three');
    }

    public function test_off_hours_and_silent_shops_need_the_shops_own_history_and_then_show_the_numbers(): void
    {
        $this->rc($this->posA, ['at' => '2026-11-10 03:00:00']); // today, in the middle of the night

        $r = $this->build(['security_off_hours_today', 'security_silent_shops']);
        $off = $this->payload($r, 'security_off_hours_today');
        $this->assertSame('—', $off['value']);
        $this->assertStringStartsWith('Not enough data', $off['note']);
        $this->assertSame('empty', $this->payload($r, 'security_silent_shops')['state']);

        // Three earlier Tuesdays, 12 receipts an hour from 09:00 to 14:59 = 216 receipts of normal hours.
        foreach (['2026-10-20', '2026-10-27', '2026-11-03'] as $day) {
            for ($h = 9; $h <= 14; $h++) {
                for ($i = 0; $i < 12; $i++) {
                    $this->rc($this->posB, ['at' => sprintf('%s %02d:%02d:00', $day, $h, $i * 4)]);
                }
            }
        }

        $r = $this->build(['security_off_hours_today', 'security_silent_shops']);
        $off = $this->payload($r, 'security_off_hours_today');
        $this->assertSame('1', $off['value']);
        $this->assertSame('none', $off['status'], 'one receipt is a low signal');
        $silent = $this->payload($r, 'security_silent_shops');
        $this->assertSame('Alpha', $silent['items'][0]['label']);
        $this->assertSame('3 h', $silent['items'][0]['cells'][0]);
        $this->assertSame('warn', $silent['items'][0]['status']);
    }

    // ---------------------------------------------------------------- who sees what

    public function test_cashier_names_and_security_widgets_are_absent_on_public_links_and_for_non_admins(): void
    {
        $this->weekFixture();
        $now = $this->build(self::KEYS); // an admin sees them
        $this->assertCount(count(self::KEYS), $now['widgets']);
        $this->assertStringContainsString('Bob', json_encode($now));

        $viewers = [
            'public link (even with an admin signed in)' => $this->build(self::KEYS, public: true),
            'operator' => $this->build(self::KEYS, User::factory()->operator()->create()),
            'monitor role' => $this->build(self::KEYS, User::factory()->monitor()->create()),
        ];
        foreach ($viewers as $who => $result) {
            $this->assertSame([], $result['widgets'], $who);
            $this->assertSame([], $result['datasets'], $who.': the dataset is not even built');
            $this->assertStringNotContainsString('Bob', json_encode($result), $who);
        }
    }

    public function test_a_guest_scope_gets_no_security_widgets(): void
    {
        $this->weekFixture();
        $layout = Grid::add([], WidgetCatalog::find('security_warnings_count'), (string) Str::uuid());

        $result = MonitorData::build($this->scopeFor($this->customMonitor([]), [$this->a->id]), $layout);

        $this->assertSame([], $result['widgets']);
    }

    // ---------------------------------------------------------------- dataset economy

    public function test_the_security_datasets_are_built_only_when_a_visible_security_widget_needs_them(): void
    {
        $this->weekFixture();

        $plain = $this->build(['sales_revenue', 'receipt_count']);
        $this->assertNotContains('security_week', $plain['datasets']);
        $this->assertNotContains('security_today', $plain['datasets']);

        $week = $this->build(['security_warnings_count', 'security_refund_by_cashier', 'security_cancelled_rate', 'security_discount_by_cashier']);
        $this->assertSame(['security_week'], $week['datasets'], 'four widgets, one dataset, and the today one is never touched');

        $today = $this->build(['security_silent_shops']);
        $this->assertSame(['security_today'], $today['datasets']);
    }

    public function test_the_catalog_keeps_the_expensive_limit_for_the_whole_group(): void
    {
        $layout = [];
        foreach (self::KEYS as $key) {
            $layout = Grid::add($layout, WidgetCatalog::find($key), (string) Str::uuid());
        }
        $expensive = count(array_filter(self::KEYS, fn ($k) => WidgetCatalog::find($k)->cost === 'expensive'));

        $this->assertLessThanOrEqual(WidgetCatalog::MAX_EXPENSIVE, $expensive);
        $this->assertSame([], array_filter(LayoutValidator::validate($layout)['errors'], fn ($e) => str_contains($e, 'expensive')));
    }
}
