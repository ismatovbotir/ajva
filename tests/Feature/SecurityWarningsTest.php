<?php

namespace Tests\Feature;

use App\Livewire\Security\Cashier;
use App\Livewire\Security\Warnings;
use App\Models\Item;
use App\Models\Pos;
use App\Models\Shop;
use App\Models\User;
use App\Services\SecurityWarnings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Security warnings from receipts: every type flagged vs not flagged, minimum samples, the sale / refund / cancelled /
 * storno definitions, shop scope, admin-only pages, the Generate pattern, no cost figures and the Telegram digest.
 * Time is frozen at 2026-11-10 12:00; the period is 2026-11-04 .. 2026-11-10.
 */
class SecurityWarningsTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private Pos $posA;

    private Pos $posB;

    /** @var array<int, int> next receipt number per till */
    private array $seq = [];

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        Carbon::setTestNow('2026-11-10 12:00:00');

        $this->shop = Shop::factory()->create(['name' => 'Alpha']);
        $this->posA = Pos::factory()->create(['shop_id' => $this->shop->id, 'name' => 'Till A']);
        $this->posB = Pos::factory()->create(['shop_id' => $this->shop->id, 'name' => 'Till B']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Inserts one receipt (query builder, no model) and returns its id. Numbers run 1, 2, 3 ... per till. */
    private function rc(Pos $pos, array $o = [], ?Shop $shop = null): int
    {
        $at = $o['at'] ?? '2026-11-09 12:00:00';
        unset($o['at']);
        $this->seq[$pos->id] = ($this->seq[$pos->id] ?? 0) + 1;

        return DB::table('receipts')->insertGetId($o + [
            'pos_id' => $pos->id, 'shop_id' => ($shop ?? $this->shop)->id, 'number' => (string) $this->seq[$pos->id],
            'cashier' => 'Anna', 'total' => 100, 'discount' => 0, 'active' => 1, 'sell' => 1, 'session' => 1,
            'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    private function many(int $n, Pos $pos, array $o = [], ?Shop $shop = null): void
    {
        for ($i = 0; $i < $n; $i++) {
            $this->rc($pos, $o, $shop);
        }
    }

    private function line(int $receiptId, ?Item $item = null, float $qty = 1, bool $storno = false): void
    {
        DB::table('receipt_items')->insert([
            'receipt_id' => $receiptId, 'item_id' => ($item ?? Item::factory()->create())->id, 'storno' => $storno ? 1 : 0, 'active' => 1,
            'qty' => $qty, 'price' => 10, 'discount' => 0, 'total' => 10 * $qty, 'receipt_active' => 1, 'receipt_sell' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function build(?array $shopIds = null, string $from = '2026-11-04', string $to = '2026-11-10'): array
    {
        return app(SecurityWarnings::class)->build(Carbon::parse($from), Carbon::parse($to), $shopIds ?? [$this->shop->id], now());
    }

    /** @return array<int, array<string, mixed>> */
    private function rows(array $report, string $type): array
    {
        return array_values(array_filter($report['warnings'], fn ($w) => $w['type'] === $type));
    }

    // -------------------------------------------------------------------------------------------- refunds / cancelled

    public function test_refund_abuse_is_flagged_against_the_rest_of_the_chain_with_minimum_samples(): void
    {
        $this->many(60, $this->posA);
        $this->many(10, $this->posA, ['cashier' => 'Carl']);
        $this->many(5, $this->posA, ['cashier' => 'Carl', 'sell' => 0]);    // 5 of 15: too small a sample to judge
        $this->many(30, $this->posB, ['cashier' => 'Bob']);
        $this->many(10, $this->posB, ['cashier' => 'Bob', 'sell' => 0]);    // 10 of 40 refunds
        $this->many(5, $this->posA, ['active' => 0, 'total' => 0]);         // cancelled receipts are never refunds

        $report = $this->build();
        $refunds = $this->rows($report, 'refund_abuse');

        $this->assertCount(1, $refunds, 'Bob only; the till he works alone on is not repeated, Carl is below the sample');
        $this->assertSame('Bob', $refunds[0]['cashier']);
        $this->assertSame('high', $refunds[0]['severity']);
        $this->assertSame(40, $refunds[0]['sample']);
        $this->assertEquals(33.3, $refunds[0]['value']);
        $this->assertNotNull($refunds[0]['baseline']);
        $this->assertStringContainsString('rest of the chain', $refunds[0]['finding']);
        $this->assertCount(5, $refunds[0]['receipt_ids']);
        $this->assertStringContainsString('security/details', $refunds[0]['evidence_url']);
    }

    public function test_repeated_and_unmatched_refunds_ignore_storno_lines_and_need_history(): void
    {
        $x = Item::factory()->create(['name' => 'Item X']);
        $y = Item::factory()->create(['name' => 'Item Y']);
        $z = Item::factory()->create(['name' => 'Item Z']);

        $this->rc($this->posA, ['at' => '2026-08-01 10:00:00']); // history older than the look-back window
        $oldSale = $this->rc($this->posA, ['at' => '2026-10-20 10:00:00']);
        $this->line($oldSale, $y);

        for ($i = 5; $i <= 7; $i++) {
            $this->line($this->rc($this->posA, ['cashier' => 'Bob', 'sell' => 0, 'at' => "2026-11-0{$i} 11:00:00"]), $x); // never sold
            $this->line($this->rc($this->posA, ['cashier' => 'Cara', 'sell' => 0, 'at' => "2026-11-0{$i} 12:00:00"]), $y); // sold on 10-20
            $this->line($this->rc($this->posA, ['cashier' => 'Dina', 'sell' => 0, 'at' => "2026-11-0{$i} 13:00:00"]), $z, 1, true); // storno lines
        }

        $report = $this->build();

        $repeat = $this->rows($report, 'refund_repeat');
        $this->assertEqualsCanonicalizing(['Bob', 'Cara'], array_column($repeat, 'cashier'));
        $this->assertSame('medium', $repeat[0]['severity']);

        $unmatched = $this->rows($report, 'refund_unmatched');
        $this->assertCount(1, $unmatched, 'only Bob refunded an item with no earlier sale; storno lines never count');
        $this->assertSame('Bob', $unmatched[0]['cashier']);
        $this->assertSame(3, $unmatched[0]['value']);

        // Without history older than the window the check is skipped (no false alarms after a migration).
        $fresh = Shop::factory()->create();
        $posF = Pos::factory()->create(['shop_id' => $fresh->id]);
        for ($i = 5; $i <= 7; $i++) {
            $this->line($this->rc($posF, ['cashier' => 'Eli', 'sell' => 0, 'at' => "2026-11-0{$i} 11:00:00"], $fresh), $x);
        }
        $this->assertSame([], $this->rows($this->build([$fresh->id]), 'refund_unmatched'));
    }

    public function test_cancelled_receipts_are_counted_with_without_lines_and_with_a_payment_separately(): void
    {
        $this->many(60, $this->posA);
        $this->many(40, $this->posB, ['cashier' => 'Dan']);
        for ($i = 0; $i < 8; $i++) {
            $id = $this->rc($this->posB, ['cashier' => 'Dan', 'active' => 0, 'total' => 0]);
            if ($i < 3) {
                DB::table('receipt_payments')->insert(['receipt_id' => $id, 'payment' => 'Cash', 'value' => 50, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        $this->line($this->rc($this->posB, ['cashier' => 'Dan', 'active' => 0, 'total' => 0])); // cancelled WITH a line

        $report = $this->build();

        $all = $this->rows($report, 'cancelled_rate');
        $empty = $this->rows($report, 'cancelled_empty');
        $paid = $this->rows($report, 'cancelled_paid');

        $this->assertCount(1, $all);
        $this->assertSame('Dan', $all[0]['cashier']);
        $this->assertEquals(18.4, $all[0]['value']); // 9 of 49
        $this->assertSame('high', $all[0]['severity']);
        $this->assertEquals(16.3, $empty[0]['value']); // 8 without lines
        $this->assertEquals(6.1, $paid[0]['value']);   // 3 with a recorded payment
        $this->assertSame([], array_filter($report['warnings'], fn ($w) => $w['cashier'] === 'Anna'));
    }

    // -------------------------------------------------------------------------------------------- discounts

    public function test_deep_discounts_cluster_per_cashier_and_shallow_ones_do_not_count(): void
    {
        $this->many(57, $this->posA);
        $this->many(3, $this->posA, ['total' => 90, 'discount' => 10]);      // 10 % deep: not deep enough
        $this->many(20, $this->posB, ['cashier' => 'Eve']);
        $this->many(10, $this->posB, ['cashier' => 'Eve', 'total' => 50, 'discount' => 50]); // 50 %

        $deep = $this->rows($this->build(), 'discount_deep');

        $this->assertCount(1, $deep);
        $this->assertSame('Eve', $deep[0]['cashier']);
        $this->assertEquals(33.3, $deep[0]['value']);
        $this->assertSame('high', $deep[0]['severity']);
        $this->assertSame(30, $deep[0]['sample']);
    }

    public function test_deep_discounts_in_the_last_hour_of_the_day_are_flagged_on_days_with_enough_receipts(): void
    {
        $this->many(25, $this->posA, ['at' => '2026-11-09 12:00:00']);
        $this->rc($this->posA, ['cashier' => 'Eve', 'total' => 50, 'discount' => 50, 'at' => '2026-11-09 10:00:00']);
        foreach (['20:10', '20:20', '20:40'] as $t) {
            $this->rc($this->posA, ['cashier' => 'Eve', 'total' => 50, 'discount' => 50, 'at' => "2026-11-09 {$t}:00"]);
        }
        $this->rc($this->posA, ['at' => '2026-11-09 20:50:00']); // the shop's last sale of the day

        $late = $this->rows($this->build(), 'discount_late');

        $this->assertCount(1, $late);
        $this->assertSame('Eve', $late[0]['cashier']);
        $this->assertSame(3, $late[0]['value']);
        $this->assertSame(4, $late[0]['sample']);
        $this->assertSame('medium', $late[0]['severity']);

        // A quiet day (fewer than 20 receipts) is not judged.
        DB::table('receipts')->where('created_at', '2026-11-09 12:00:00')->limit(20)->delete();
        $this->assertSame([], $this->rows($this->build(), 'discount_late'));
    }

    // -------------------------------------------------------------------------------------------- hours

    public function test_off_hours_use_the_shops_own_hours_and_need_enough_history(): void
    {
        for ($i = 0; $i < 600; $i++) {
            $this->rc($this->posA, ['at' => sprintf('2026-11-0%d %02d:30:00', 3 + $i % 7, 9 + $i % 12)]);
        }
        $this->many(3, $this->posA, ['cashier' => 'Gus', 'at' => '2026-11-08 03:15:00']);

        $quiet = Shop::factory()->create();
        $posQ = Pos::factory()->create(['shop_id' => $quiet->id]);
        $this->many(50, $posQ, ['at' => '2026-11-08 12:00:00'], $quiet);
        $this->rc($posQ, ['at' => '2026-11-08 03:00:00', 'cashier' => 'Hal'], $quiet);

        $report = $this->build([$this->shop->id, $quiet->id]);
        $off = $this->rows($report, 'off_hours');

        $this->assertCount(1, $off, 'the second shop has too little history to know its normal hours');
        $this->assertSame('Gus', $off[0]['cashier']);
        $this->assertSame(3, $off[0]['value']);
        $this->assertSame('medium', $off[0]['severity']);
        $this->assertStringContainsString('03:00', $off[0]['finding']);
    }

    public function test_a_silent_stretch_inside_the_usual_opening_hours_is_flagged_against_the_weekday_median(): void
    {
        $rows = [];
        for ($d = Carbon::parse('2026-10-14'); $d->lte(Carbon::parse('2026-11-10')); $d->addDay()) {
            for ($h = 9; $h <= 20; $h++) {
                $date = $d->toDateString();
                if ($date === '2026-11-10' && $h >= 12) {
                    continue; // today: only the completed hours exist
                }
                if ($date === '2026-11-09' && $h >= 12 && $h <= 16) {
                    continue; // the silent stretch: 12:00-16:59
                }
                for ($k = 0; $k < 5; $k++) {
                    $rows[] = [
                        'pos_id' => $this->posA->id, 'shop_id' => $this->shop->id, 'number' => 'n'.count($rows), 'cashier' => 'Anna',
                        'total' => 100, 'discount' => 0, 'active' => 1, 'sell' => 1, 'session' => 0,
                        'created_at' => "{$date} ".sprintf('%02d', $h).':10:00', 'updated_at' => "{$date} ".sprintf('%02d', $h).':10:00',
                    ];
                }
            }
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('receipts')->insert($chunk);
        }

        $silent = $this->rows($this->build(null, '2026-11-09', '2026-11-10'), 'silent_shop');

        $this->assertCount(1, $silent);
        $this->assertSame(5, $silent[0]['value']);
        $this->assertEquals(25, $silent[0]['baseline']);
        $this->assertSame('medium', $silent[0]['severity']);
        $this->assertStringContainsString('2026-11-09', $silent[0]['finding']);
        $this->assertStringContainsString('date=2026-11-09', $silent[0]['evidence_url']);
    }

    public function test_tills_that_never_sent_a_receipt_are_listed_when_the_shop_trades(): void
    {
        $this->many(25, $this->posA);
        $idle = Pos::factory()->create(['shop_id' => $this->shop->id, 'name' => 'Idle till']);

        $rows = $this->rows($this->build(), 'silent_till');

        $this->assertEqualsCanonicalizing(['Till B', 'Idle till'], array_column($rows, 'pos'), 'registered tills with no receipt at all');
        $this->assertSame('low', $rows[0]['severity']);
        $this->assertSame($idle->id, collect($rows)->firstWhere('pos', 'Idle till')['pos_id']);
    }

    // -------------------------------------------------------------------------------------------- numbering

    public function test_missing_numbers_inside_a_shift_and_duplicate_looking_receipts(): void
    {
        foreach (array_diff(range(1, 20), [7, 8, 9]) as $n) {
            $this->rc($this->posA, ['session' => 5, 'number' => (string) $n]);
        }
        foreach (range(1, 12) as $n) {
            $this->rc($this->posA, ['session' => 6, 'number' => (string) $n, 'at' => '2026-11-09 13:00:00']);   // complete
        }
        foreach (array_diff(range(1, 15), [5]) as $n) {
            $this->rc($this->posA, ['session' => 0, 'number' => (string) $n, 'at' => '2026-11-09 14:00:00']);   // no shift: numbers restart, skipped
        }
        foreach (range(1, 12) as $n) {
            $this->rc($this->posA, ['session' => 7, 'number' => 'A-'.$n, 'at' => '2026-11-09 15:00:00']);       // not numeric: skipped
        }

        $gaps = $this->rows($this->build(), 'number_gap');
        $this->assertCount(1, $gaps);
        $this->assertSame(3, $gaps[0]['value']);
        $this->assertSame('medium', $gaps[0]['severity']);
        $this->assertSame('Till A', $gaps[0]['pos']);
        $this->assertStringContainsString('7, 8, 9', $gaps[0]['evidence']);

        $this->rc($this->posB, ['number' => '77', 'total' => 250, 'session' => 1, 'at' => '2026-11-09 12:00:00']);
        $this->rc($this->posB, ['number' => '77', 'total' => 250, 'session' => 2, 'at' => '2026-11-09 12:00:30']);
        $this->rc($this->posB, ['number' => '78', 'total' => 250, 'session' => 1, 'at' => '2026-11-09 12:00:00']);
        $this->rc($this->posB, ['number' => '78', 'total' => 250, 'session' => 2, 'at' => '2026-11-09 12:10:00']);  // too far apart
        $this->rc($this->posB, ['number' => '79', 'total' => 250, 'session' => 1, 'at' => '2026-11-09 12:00:00']);
        $this->rc($this->posB, ['number' => '79', 'total' => 260, 'session' => 2, 'at' => '2026-11-09 12:00:10']);  // other total

        $dups = $this->rows($this->build(), 'duplicate_receipt');
        $this->assertCount(1, $dups);
        $this->assertSame(2, $dups[0]['value']);
        $this->assertCount(2, $dups[0]['receipt_ids']);
    }

    // -------------------------------------------------------------------------------------------- data integrity

    public function test_data_integrity_signals_each_have_their_own_row(): void
    {
        $other = Shop::factory()->create();
        $foreignPos = Pos::factory()->create(['shop_id' => $other->id]);

        $this->many(25, $this->posA);
        $this->rc($this->posA, ['total' => 0]);
        $this->rc($this->posA, ['total' => 100, 'discount' => -10]);
        $this->line($this->rc($this->posA), null, 0);                 // quantity 0
        $this->line($this->rc($this->posA), null, 0, true);           // a storno line with qty 0 does not count
        $this->rc($this->posA, ['at' => '2026-11-10 13:00:00']);      // 1 hour ahead of "now"
        $this->rc($foreignPos, [], $this->shop);                      // receipt shop differs from the till's shop
        $this->rc($this->posA, ['active' => 0, 'total' => 0]);        // cancelled: never a sale-total problem

        $report = $this->build();

        $this->assertSame(1, $this->rows($report, 'data_total')[0]['value']);
        $this->assertSame(1, $this->rows($report, 'data_discount')[0]['value']);
        $this->assertSame(1, $this->rows($report, 'data_qty')[0]['value']);
        $this->assertSame(1, $this->rows($report, 'data_future')[0]['value']);
        $this->assertSame(1, $this->rows($report, 'data_shop')[0]['value']);
        $this->assertSame('medium', $this->rows($report, 'data_total')[0]['severity']);

        // 10+ bad totals out of 20+ sales is a high signal.
        $this->many(10, $this->posA, ['total' => 0]);
        $this->assertSame('high', $this->rows($this->build(), 'data_total')[0]['severity']);
    }

    public function test_an_unusual_single_item_basket_is_flagged_and_storno_lines_are_ignored(): void
    {
        $this->many(100, $this->posA);
        $item = Item::factory()->create(['name' => 'Rice']);

        $bulk = $this->rc($this->posA, ['total' => 20000]);
        $this->line($bulk, $item, 50);
        $this->line($bulk, null, 100, true);                          // storno line: ignored, still one line
        $mixed = $this->rc($this->posA, ['total' => 20000]);
        foreach ([1, 2, 3] as $i) {
            $this->line($mixed, null, 20);
        }
        $few = $this->rc($this->posA, ['total' => 20000]);
        $this->line($few, $item, 2);                                  // big total but a small quantity

        $rows = $this->rows($this->build(), 'unusual_basket');

        $this->assertCount(1, $rows);
        $this->assertSame([$bulk], $rows[0]['receipt_ids']);
        $this->assertSame('high', $rows[0]['severity']);
        $this->assertEquals(20000, $rows[0]['value']);
        $this->assertEquals(100, $rows[0]['baseline']);
        $this->assertStringContainsString('Rice', $rows[0]['finding']);
    }

    // -------------------------------------------------------------------------------------------- scope, shape

    public function test_shop_scope_is_respected_and_no_cost_or_profit_field_exists(): void
    {
        $other = Shop::factory()->create(['name' => 'Beta']);
        $posO = Pos::factory()->create(['shop_id' => $other->id]);
        $this->rc($this->posA, ['total' => 0]);
        $this->rc($posO, ['total' => 0], $other);

        $one = $this->build([$this->shop->id]);
        $both = $this->build([$this->shop->id, $other->id]);

        $this->assertSame([$this->shop->id], array_values(array_unique(array_column($this->rows($one, 'data_total'), 'shop_id'))));
        $this->assertCount(2, $this->rows($both, 'data_total'));
        $this->assertSame([], $this->build([])['warnings']);

        $keys = [];
        array_walk_recursive($both, function ($v, $k) use (&$keys) {
            $keys[] = (string) $k;
        });
        foreach ($keys as $k) {
            $this->assertDoesNotMatchRegularExpression('/cost|profit|margin/i', $k);
        }
    }

    public function test_kpi_counts_severities_and_affected_subjects_and_the_stamp_names_the_period(): void
    {
        $this->many(25, $this->posA);
        $this->many(10, $this->posA, ['total' => 0]);   // high data_total
        $this->rc($this->posA, ['discount' => -5]);     // medium data_discount

        $report = $this->build();

        $this->assertSame(1, $report['kpi']['shops']);
        $this->assertSame($report['kpi']['high'] + $report['kpi']['medium'] + $report['kpi']['low'], $report['kpi']['total']);
        $this->assertGreaterThanOrEqual(1, $report['kpi']['high']);
        $this->assertSame('2026-11-04', $report['stamp']['from']);
        $this->assertSame('2026-11-10', $report['stamp']['to']);
        $this->assertSame(36, $report['stamp']['receipts']);
        $this->assertSame('high', $report['warnings'][0]['severity'], 'most severe first');
        $this->assertNotEmpty($report['notes']);
    }

    // -------------------------------------------------------------------------------------------- pages

    public function test_pages_are_admin_only_and_the_menu_group_shows_for_admins(): void
    {
        $this->get(route('security.warnings'))->assertRedirect();

        $this->actingAs(User::factory()->operator()->create())->get(route('security.warnings'))->assertForbidden();
        $this->actingAs(User::factory()->operator()->create())->get(route('security.cashier', ['shop' => $this->shop->id, 'cashier' => 'Anna']))->assertForbidden();

        $admin = User::factory()->create();
        $this->actingAs($admin)->get(route('security.warnings'))->assertOk()->assertSee('Security warnings')->assertSee(route('security.warnings'), false);
        $this->actingAs(User::factory()->operator()->create())->get('/')->assertDontSee(route('security.warnings'), false);

        Livewire::actingAs(User::factory()->operator()->create())->test(Warnings::class)->assertForbidden();
    }

    public function test_nothing_is_calculated_before_generate_and_every_press_recalculates(): void
    {
        $this->many(20, $this->posA);
        $this->many(5, $this->posB);
        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            if (str_contains($q->sql, 'receipts')) {
                $queries[] = $q->sql;
            }
        });

        $c = Livewire::actingAs(User::factory()->create())->test(Warnings::class)
            ->assertSee('No report yet');
        $this->assertSame([], $queries, 'opening the page must not touch the receipts');

        $c->call('generate')->assertHasNoErrors();
        $this->assertNotEmpty($queries);
        $this->assertSame(0, $c->viewData('report')['kpi']['total']);
        $c->assertSee('No warnings in this period')->assertSee('Checked 2026-11-04 to 2026-11-10, 25 receipts');

        // A new problem appears; the 10 minute cache would hide it, a second Generate must not.
        $this->rc($this->posA, ['total' => 0]);
        $c->call('generate');
        $this->assertSame(1, $c->viewData('report')['kpi']['total']);
        $c->assertSee('Sale receipt with total of zero or less')->assertSee('Review');

        // The Generate button never disappears and a period over the maximum is refused.
        $c->assertSee('Generate')
            ->set('dateFrom', '2026-01-01')->call('generate')->assertHasErrors('dateTo');
    }

    public function test_the_list_filters_by_severity_and_the_page_only_uses_selected_shops(): void
    {
        $other = Shop::factory()->create();
        $posO = Pos::factory()->create(['shop_id' => $other->id]);
        $this->rc($this->posA, ['total' => 0]);
        $this->rc($posO, ['total' => 0], $other);

        $c = Livewire::actingAs(User::factory()->create())->test(Warnings::class)
            ->set('shopIds', [$this->shop->id])->call('generate');
        $this->assertCount(1, $c->viewData('report')['warnings']);

        $c->set('severity', 'high');
        $this->assertCount(0, $c->viewData('report')['warnings']);
        $c->set('severity', 'all')->set('type', 'data_total');
        $this->assertCount(1, $c->viewData('report')['warnings']);
    }

    public function test_the_cashier_drill_down_shows_the_numbers_against_the_chain_and_the_flagged_receipts(): void
    {
        $this->many(60, $this->posA);
        $this->many(30, $this->posB, ['cashier' => 'Bob']);
        $refunds = [];
        for ($i = 0; $i < 10; $i++) {
            $refunds[] = $this->rc($this->posB, ['cashier' => 'Bob', 'sell' => 0, 'at' => '2026-11-09 15:00:00']);
        }

        $detail = app(SecurityWarnings::class)->detail([$this->shop->id], $this->shop->id, 'Bob', null, now());
        $metrics = collect($detail['metrics'])->keyBy('key');

        $this->assertSame(40, $detail['receipts']);
        $this->assertSame(30, $detail['sale_count']);
        $this->assertEquals(33.3, round($metrics['refund_amount']['rate'], 1));
        $this->assertEquals(0.0, $metrics['refund_amount']['base']);
        $this->assertTrue($metrics['refund_amount']['judged']);
        $this->assertCount(30, $detail['days']);
        $this->assertSame(10, collect($detail['days'])->firstWhere('date', '2026-11-09')['refunds']);

        $admin = User::factory()->create();
        $this->actingAs($admin)->get(route('security.cashier', ['shop' => $this->shop->id, 'cashier' => 'Bob']))
            ->assertOk()->assertSee('Bob')->assertSee('Refund amount share of sales')->assertSee('Above the chain');

        Livewire::actingAs($admin)->withQueryParams(['shop' => $this->shop->id, 'cashier' => 'Bob'])->test(Cashier::class)
            ->assertSee('Receipts to review')->assertSee('Refund')
            ->assertSee(route('receipts.show', ['receipt' => $refunds[0]]), false);

        // A till view and a bad address.
        $this->actingAs($admin)->get(route('security.cashier', ['shop' => $this->shop->id, 'pos' => $this->posB->id]))->assertOk()->assertSee('Till B');
        $this->actingAs($admin)->get(route('security.cashier', ['shop' => $this->shop->id]))->assertNotFound();
        $this->actingAs($admin)->get(route('security.cashier', ['shop' => 99999, 'cashier' => 'Bob']))->assertNotFound();
    }

    public function test_the_receipts_page_opens_on_the_day_given_in_the_link(): void
    {
        $r = $this->rc($this->posA, ['at' => '2026-11-03 10:00:00']);

        $this->actingAs(User::factory()->create())->get(route('receipts.index', ['date' => '2026-11-03']))
            ->assertOk()->assertSee('2026-11-03');
        $this->assertGreaterThan(0, $r);
    }

    // -------------------------------------------------------------------------------------------- Telegram digest

    public function test_the_digest_goes_to_admins_once_a_day_and_only_when_high_warnings_exist(): void
    {
        config(['services.telegram.bot_token' => 'BOT:TOKEN', 'app.url' => 'https://panel.test']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 7]], 200)]);
        User::factory()->create(['telegram_chat_id' => '999']);
        User::factory()->create(['telegram_chat_id' => null]);

        // Nothing wrong: silent.
        $this->many(25, $this->posA, ['at' => '2026-11-10 09:00:00']);
        $this->artisan('security:digest')->assertExitCode(0);
        Http::assertNothingSent();

        $this->many(10, $this->posA, ['total' => 0, 'at' => '2026-11-10 09:30:00']);
        $this->artisan('security:digest')->assertExitCode(0);
        $this->artisan('security:digest')->assertExitCode(0); // the same day again: no second notice

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['chat_id'] === '999'
            && str_contains($request['text'], 'High security warnings since yesterday: 1')
            && $request['reply_markup']['inline_keyboard'][0][0]['url'] === 'https://panel.test/security/warnings');

        // No bot token: skipped silently.
        config(['services.telegram.bot_token' => '']);
        \Illuminate\Support\Facades\Cache::flush();
        $this->artisan('security:digest')->assertExitCode(0);
        Http::assertSentCount(1);
    }
}
