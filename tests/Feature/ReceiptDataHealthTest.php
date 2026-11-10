<?php

namespace Tests\Feature;

use App\Livewire\Monitoring\DataHealth as DataHealthPage;
use App\Models\Item;
use App\Models\ItemOrderRule;
use App\Models\Pos;
use App\Models\Receipt;
use App\Models\Shop;
use App\Models\User;
use App\Monitor\LayoutValidator;
use App\Monitor\MonitorData;
use App\Monitor\WidgetCatalog;
use App\Services\DataHealth;
use App\Services\SecurityWarnings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\BuildsCustomMonitors;
use Tests\TestCase;

/**
 * Receipt data health: ingestion time (first insert only), validation (reject only the impossible, flag the suspicious),
 * rejection counters, the Data health board, the Security signals and the monitor widgets. Time is frozen at 2026-11-10 12:00.
 */
class ReceiptDataHealthTest extends TestCase
{
    use BuildsCustomMonitors;
    use RefreshDatabase;

    private Shop $a;

    private Shop $b;

    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        Carbon::setTestNow('2026-11-10 12:00:00');
        $this->a = Shop::factory()->create(['name' => 'Alpha']);
        $this->b = Shop::factory()->create(['name' => 'Beta']);
        $this->item = Item::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param  array<string, mixed>  $o */
    private function receipt(string $number, array $o = [], array $position = []): array
    {
        return array_merge([
            'shop' => $this->a->id, 'pos' => 11, 'session' => 1, 'number' => $number,
            'openDate' => '09.11.26', 'openTime' => '10:00:00', 'status' => 'success',
            'total' => 1000, 'sum' => 1000,
            'positions' => [array_merge(['item' => ['id' => $this->item->id], 'qty' => 1, 'storno' => 0, 'sum' => 1000, 'totalSum' => 1000], $position)],
            'payments' => [['name' => 'cash', 'value' => 1000]],
        ], $o);
    }

    private function send(array $payload)
    {
        return $this->withToken('test-pos-token')->postJson('/api/receipts', $payload);
    }

    private function flags(string $number): ?string
    {
        return Receipt::query()->where('number', $number)->value('dq_flags');
    }

    // ---------------------------------------------------------------- ingested_at

    public function test_ingested_at_is_set_on_the_first_insert_only(): void
    {
        $this->send($this->receipt('R1'))->assertStatus(202);
        $first = Receipt::query()->where('number', 'R1')->firstOrFail()->ingested_at;
        $this->assertSame('2026-11-10 12:00:00', $first->format('Y-m-d H:i:s'));

        Carbon::setTestNow('2026-11-11 08:30:00');
        $this->send($this->receipt('R1', ['total' => 900, 'sum' => 900]))->assertStatus(202);
        $again = Receipt::query()->where('number', 'R1')->firstOrFail();

        $this->assertSame(1, Receipt::query()->where('number', 'R1')->count());
        $this->assertSame('2026-11-10 12:00:00', $again->ingested_at->format('Y-m-d H:i:s'), 'a resend never moves it');
        $this->assertEquals(900, $again->total, 'the resend still replaced the data');
    }

    public function test_an_old_receipt_without_ingested_at_stays_null_when_it_is_resent(): void
    {
        $pos = Pos::factory()->create(['id' => 11, 'shop_id' => $this->a->id]);
        DB::table('receipts')->insert(['pos_id' => $pos->id, 'shop_id' => $this->a->id, 'number' => 'OLD', 'session' => 1, 'total' => 5, 'discount' => 0, 'active' => 1, 'sell' => 1, 'created_at' => '2026-05-01 10:00:00', 'updated_at' => '2026-05-01 10:00:00']);

        $this->send($this->receipt('OLD'))->assertStatus(202);

        $this->assertNull(Receipt::query()->where('number', 'OLD')->value('ingested_at'), 'unknown, never faked');
    }

    // ---------------------------------------------------------------- validation: reject the impossible

    public function test_the_impossible_is_rejected_and_counted(): void
    {
        $this->send($this->receipt('Z1', [], ['qty' => 0]))->assertStatus(422)->assertJsonValidationErrors(['positions.0.qty']);
        $this->send($this->receipt('Z2', [], ['qty' => 100001]))->assertStatus(422);
        $this->send($this->receipt('Z3', ['openDate' => '12.11.26']))->assertStatus(422)->assertJsonValidationErrors(['openDate']);
        $this->send($this->receipt('Z4', [], ['item' => ['id' => 987654]]))->assertStatus(422);

        $this->assertSame(0, Receipt::count(), 'nothing stored');
        $by = DB::table('receipt_rejections')->pluck('reason')->sort()->values()->all();
        $this->assertSame(['future_date', 'qty_absurd', 'qty_zero', 'unknown_item'], $by);
        $this->assertSame(987654, (int) DB::table('receipt_rejections')->where('reason', 'unknown_item')->value('item_id'));
        $this->assertSame('pos', DB::table('receipt_rejections')->value('source'));
        foreach (['future_date', 'qty_absurd', 'qty_zero', 'unknown_item'] as $reason) {
            $this->assertSame(1, (int) DB::table('receipt_rejection_counts')->where('stat_date', '2026-11-10')->where('reason', $reason)->value('total'), $reason);
        }
    }

    public function test_the_suspicious_is_accepted_and_flagged(): void
    {
        $rule = ItemOrderRule::query()->create(['shop_id' => $this->a->id, 'item_id' => $this->item->id, 'min' => 1, 'max' => 5]);

        $this->send($this->receipt('N1', [], ['qty' => -2]))->assertStatus(202);                        // negative qty on a sell line
        $this->send($this->receipt('N2', ['total' => 0, 'sum' => 0]))->assertStatus(202);               // sale total <= 0
        $this->send($this->receipt('N3', ['sum' => 900]))->assertStatus(202);                           // sum < total
        $this->send($this->receipt('N4', [], ['qty' => 60]))->assertStatus(202);                        // 60 > 10 x max 5
        $this->send($this->receipt('N5', ['openDate' => '01.01.15']))->assertStatus(202);               // older than 5 years
        $this->send($this->receipt('N6', ['openDate' => '11.11.26', 'openTime' => '11:00:00']))->assertStatus(202);   // 23 h ahead: within 1 day
        $this->send($this->receipt('N7'))->assertStatus(202);                                           // clean
        $this->send($this->receipt('N8', ['total' => 0, 'sum' => 0, 'status' => 'cancel']))->assertStatus(202);   // cancelled: total 0 is normal
        $this->send($this->receipt('N9', ['sell' => false], ['qty' => -2]))->assertStatus(202);          // refund line: not a sale line
        $this->send($this->receipt('N10', [], ['qty' => 0, 'storno' => 1]))->assertStatus(202);          // storno qty 0 is not rejected

        $this->assertSame('neg_qty', $this->flags('N1'));
        $this->assertSame('bad_total', $this->flags('N2'));
        $this->assertSame('sum_lt_total', $this->flags('N3'));
        $this->assertSame('qty_gt_max', $this->flags('N4'));
        $this->assertSame('old_date', $this->flags('N5'));
        $this->assertNull($this->flags('N6'));
        $this->assertNull($this->flags('N7'));
        $this->assertNull($this->flags('N8'));
        $this->assertNull($this->flags('N9'));
        $this->assertNull($this->flags('N10'));
        $this->assertSame(10, Receipt::count());
        $this->assertSame(0, DB::table('receipt_rejections')->count());

        $rule->delete();
        $this->send($this->receipt('N11', [], ['qty' => 60]))->assertStatus(202);
        $this->assertNull($this->flags('N11'), 'no max rule: nothing to compare with');

        // A resend recomputes the flags from the new payload.
        $this->send($this->receipt('N1'))->assertStatus(202);
        $this->assertNull($this->flags('N1'));
    }

    public function test_the_flags_show_on_the_receipt_page(): void
    {
        $this->send($this->receipt('S1', [], ['qty' => -2]))->assertStatus(202);
        $id = Receipt::query()->where('number', 'S1')->value('id');

        $this->actingAs(User::factory()->create())->get(route('receipts.show', $id))
            ->assertOk()->assertSee('Negative quantity on a sale line');
    }

    public function test_the_legacy_switches_restore_the_old_behaviour(): void
    {
        config(['receipts.strict_validation' => false, 'receipts.flagging' => false]);

        $this->send($this->receipt('L1', [], ['qty' => 0]))->assertStatus(202);
        $this->send($this->receipt('L2', ['openDate' => '20.11.26']))->assertStatus(202);
        $this->send($this->receipt('L3', ['total' => 0, 'sum' => 0], ['qty' => -1]))->assertStatus(202);

        $this->assertSame(3, Receipt::count());
        $this->assertNull($this->flags('L3'));
        $this->assertSame(0, DB::table('receipt_rejections')->count());
    }

    public function test_the_max_qty_is_configurable(): void
    {
        config(['receipts.max_qty' => 50]);

        $this->send($this->receipt('M1', [], ['qty' => 51]))->assertStatus(422);
        $this->send($this->receipt('M2', [], ['qty' => 50]))->assertStatus(202);
    }

    public function test_only_the_newest_rejections_are_kept_but_every_one_is_counted(): void
    {
        config(['receipts.keep_rejections' => 3]);

        for ($i = 1; $i <= 5; $i++) {
            $this->send($this->receipt('K'.$i, [], ['qty' => 0]))->assertStatus(422);
        }

        $this->assertSame(3, DB::table('receipt_rejections')->count());
        $this->assertSame(['K3', 'K4', 'K5'], DB::table('receipt_rejections')->orderBy('id')->pluck('number')->all());
        $this->assertSame(5, (int) DB::table('receipt_rejection_counts')->where('reason', 'qty_zero')->value('total'));
    }

    public function test_the_history_batch_applies_the_same_rules_and_reports_each_rejection(): void
    {
        $batch = [
            $this->receipt('H1'),
            $this->receipt('H2', [], ['qty' => 0]),
            $this->receipt('H3', [], ['item' => ['id' => 555555]]),
            $this->receipt('H4', ['openDate' => '01.01.15'], ['qty' => -1]),
        ];

        $r = $this->postJson('/api/receipts/batch', $batch)->assertOk()->json();

        $this->assertSame(2, $r['accepted']);
        $this->assertSame([1, 2], array_column($r['rejected'], 'index'));
        $this->assertSame('history', Receipt::query()->where('number', 'H1')->value('source'));
        $this->assertSame('neg_qty,old_date', $this->flags('H4'), 'old and negative are accepted and flagged');
        $this->assertEqualsCanonicalizing(['qty_zero', 'unknown_item'], DB::table('receipt_rejections')->where('source', 'history')->pluck('reason')->all());
    }

    // ---------------------------------------------------------------- Security signals

    private function security(): array
    {
        return app(SecurityWarnings::class)->build(Carbon::parse('2026-11-04'), Carbon::parse('2026-11-10'), [$this->a->id], now());
    }

    /** @return array<int, array<string, mixed>> */
    private function rows(array $report, string $type): array
    {
        return array_values(array_filter($report['warnings'], fn ($w) => $w['type'] === $type));
    }

    public function test_a_receipt_that_arrives_days_late_raises_the_late_arrival_signal(): void
    {
        $this->send($this->receipt('LATE', ['openDate' => '05.11.26']))->assertStatus(202);     // 5 days old on arrival
        $this->send($this->receipt('FRESH', ['openDate' => '10.11.26', 'openTime' => '09:00:00']))->assertStatus(202);
        $this->postJson('/api/receipts/batch', [$this->receipt('HIST', ['openDate' => '01.10.26'])])->assertOk();   // history: late by design
        DB::table('receipts')->insert(['pos_id' => Receipt::first()->pos_id, 'shop_id' => $this->a->id, 'number' => 'NOIN', 'session' => 1, 'total' => 5, 'discount' => 0, 'active' => 1, 'sell' => 1, 'created_at' => '2026-10-01 10:00:00', 'updated_at' => '2026-10-01 10:00:00']);

        $late = $this->rows($this->security(), 'data_late');

        $this->assertCount(1, $late);
        $this->assertSame(1, $late[0]['value'], 'only the till receipt that really arrived late; history and rows without ingested_at are left out');
        $this->assertSame('Alpha', $late[0]['shop']);
        $this->assertSame('medium', $late[0]['severity']);
        $this->assertContains(Receipt::query()->where('number', 'LATE')->value('id'), $late[0]['receipt_ids']);
        $this->assertSame('Receipts arriving late', $late[0]['metric']);

        $notes = implode(' ', array_column(app(SecurityWarnings::class)->notes(), 'reason'));
        $this->assertStringNotContainsString('Skipped: the feed stores no ingestion time', $notes);
    }

    public function test_flagged_receipts_are_counted_by_data_integrity_without_repeating_covered_flags(): void
    {
        $this->send($this->receipt('F1', [], ['qty' => -2]))->assertStatus(202);                // neg_qty
        $this->send($this->receipt('F2', ['openDate' => '01.01.15']))->assertStatus(202);       // old_date (outside the period: not counted)
        $this->send($this->receipt('F3', ['total' => 0, 'sum' => 0]))->assertStatus(202);       // bad_total: has its own row
        $this->send($this->receipt('F4'))->assertStatus(202);

        $report = $this->security();
        $flags = $this->rows($report, 'data_flags');

        $this->assertCount(1, $flags);
        $this->assertSame(1, $flags[0]['value']);
        $this->assertStringContainsString('Negative quantity on a sale line: 1', $flags[0]['evidence']);
        $this->assertSame(1, $this->rows($report, 'data_total')[0]['value']);
    }

    // ---------------------------------------------------------------- the board

    /** Alpha trades normally (two tills, one used); Beta traded normally until yesterday and then went quiet. */
    private function boardFixture(): void
    {
        $alphaPos = Pos::factory()->count(2)->create(['shop_id' => $this->a->id]);
        $betaPos = Pos::factory()->create(['shop_id' => $this->b->id]);
        $rows = [];
        $n = 0;
        for ($i = 1; $i <= 14; $i++) {
            $day = Carbon::now()->subDays($i)->toDateString();
            for ($k = 0; $k < 25; $k++) {
                foreach ([[$this->a, $alphaPos[0]], [$this->b, $betaPos]] as [$shop, $pos]) {
                    $rows[] = ['pos_id' => $pos->id, 'shop_id' => $shop->id, 'number' => 'B'.(++$n), 'session' => 1, 'total' => 10, 'discount' => 0, 'active' => 1, 'sell' => 1, 'created_at' => $day.' 08:00:00', 'updated_at' => $day.' 08:00:00'];
                }
            }
        }
        // Today (the last 24 h): Alpha 20 receipts, Beta 2.
        for ($k = 0; $k < 20; $k++) {
            $rows[] = ['pos_id' => $alphaPos[0]->id, 'shop_id' => $this->a->id, 'number' => 'T'.$k, 'session' => 1, 'total' => 10, 'discount' => 0, 'active' => 1, 'sell' => 1, 'created_at' => '2026-11-10 09:00:00', 'updated_at' => '2026-11-10 09:00:00'];
        }
        for ($k = 0; $k < 2; $k++) {
            $rows[] = ['pos_id' => $betaPos->id, 'shop_id' => $this->b->id, 'number' => 'U'.$k, 'session' => 1, 'total' => 10, 'discount' => 0, 'active' => 1, 'sell' => 1, 'created_at' => '2026-11-09 20:00:00', 'updated_at' => '2026-11-09 20:00:00'];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('receipts')->insert($chunk);
        }
    }

    public function test_the_board_flags_the_silent_shop_and_shows_tills_lag_and_rejections(): void
    {
        $this->boardFixture();
        $this->send($this->receipt('RJ1', [], ['item' => ['id' => 424242]]))->assertStatus(422);
        $this->send($this->receipt('RJ2', [], ['item' => ['id' => 424242]]))->assertStatus(422);
        $this->send($this->receipt('RJ3', [], ['qty' => 0]))->assertStatus(422);

        $h = app(DataHealth::class)->build(null);
        $shops = collect($h['shops'])->keyBy('name');

        $alpha = $shops['Alpha'];
        $this->assertSame('ok', $alpha['status']);
        $this->assertSame(20, $alpha['last24']);
        $this->assertEquals(25, $alpha['usual']);
        $this->assertSame([1, 2], [$alpha['tills_active'], $alpha['tills_registered']], 'one of two tills sold in the last 24 h');
        $this->assertSame(3.0, $alpha['lag_hours']);

        $beta = $shops['Beta'];
        $this->assertSame('bad', $beta['status']);
        $this->assertTrue($beta['silent']);
        $this->assertSame(2, $beta['last24']);
        $this->assertSame(1, $h['silent_count']);

        $this->assertSame(3, $h['rejections']['total24']);
        $this->assertSame(['unknown_item' => 2, 'qty_zero' => 1], $h['rejections']['by_reason']);
        $this->assertSame([['item_id' => 424242, 'count' => 2]], $h['rejections']['top_items']);
        $this->assertFalse($h['rejections']['spike'], 'fewer than 10');
    }

    public function test_a_rejection_spike_is_flagged(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->send($this->receipt('SP'.$i, [], ['qty' => 0]))->assertStatus(422);
        }

        $this->assertTrue(app(DataHealth::class)->build(null)['rejections']['spike']);
    }

    public function test_the_board_is_scoped_to_the_shops_it_is_asked_for(): void
    {
        $this->boardFixture();
        $this->send($this->receipt('SC1', ['shop' => $this->b->id], ['qty' => 0]))->assertStatus(422);

        $onlyAlpha = app(DataHealth::class)->build([$this->a->id]);

        $this->assertSame(['Alpha'], array_column($onlyAlpha['shops'], 'name'));
        $this->assertSame(0, $onlyAlpha['rejections']['total24'], 'a rejection of another shop is not shown');
        $this->assertSame(1, app(DataHealth::class)->build([$this->b->id])['rejections']['total24']);
        $this->assertSame([], app(DataHealth::class)->build([])['shops']);
    }

    public function test_the_page_is_admin_only_and_shows_the_board(): void
    {
        $this->boardFixture();

        $this->get('/data-health')->assertRedirect('/login');
        $this->actingAs(User::factory()->operator()->create())->get('/data-health')->assertForbidden();

        $this->actingAs(User::factory()->create())->get('/data-health')
            ->assertOk()->assertSee('Data health')->assertSee('Alpha')->assertSee('Beta')->assertSee('data-overall="problems"', false);

        Livewire::actingAs(User::factory()->create())->test(DataHealthPage::class)->assertSee('Rejected receipts');
        Livewire::actingAs(User::factory()->operator()->create())->test(DataHealthPage::class)->assertForbidden();
    }

    public function test_the_menu_has_data_health_after_tasks_for_admins_only(): void
    {
        $admin = $this->actingAs(User::factory()->create())->get('/')->getContent();
        $this->assertStringContainsString(route('data-health.index'), $admin);
        $this->assertGreaterThan(strpos($admin, route('monitoring.tasks')), strpos($admin, route('data-health.index')));

        $this->actingAs(User::factory()->operator()->create())->get('/')->assertDontSee(route('data-health.index'), false);
    }

    // ---------------------------------------------------------------- widgets

    public function test_the_data_health_widgets_are_in_the_system_group_and_follow_the_rules(): void
    {
        foreach (['data_health_silent_shops', 'data_health_rejections_24h'] as $key) {
            $def = WidgetCatalog::find($key);
            $this->assertNotNull($def, $key);
            $this->assertSame('System', $def->group);
            $this->assertSame('data_health', $def->dataset);
            $this->assertFalse($def->profit);
            $this->assertNotNull($def->rule);
        }
    }

    public function test_the_widgets_show_the_silent_shop_and_the_rejections(): void
    {
        $this->boardFixture();
        $this->send($this->receipt('W1', [], ['qty' => 0]))->assertStatus(422);

        $monitor = $this->customMonitor(['data_health_silent_shops', 'data_health_rejections_24h']);
        $r = MonitorData::build($this->scopeFor($monitor, null));

        $silent = $this->payload($r, 'data_health_silent_shops');
        $this->assertSame('Beta', $silent['items'][0]['label']);
        $this->assertSame('bad', $silent['items'][0]['status']);
        $this->assertSame('2', $silent['items'][0]['cells'][0]);
        $this->assertSame('Silent', $silent['items'][0]['cells'][2]);

        $rej = $this->payload($r, 'data_health_rejections_24h');
        $this->assertSame('1', $rej['value']);
        $this->assertSame('warn', $rej['status']);
        $this->assertStringContainsString('Quantity 0', $rej['note']);
    }

    public function test_the_widgets_have_sample_data_and_run_no_query_on_the_edit_canvas(): void
    {
        $monitor = $this->customMonitor(['data_health_silent_shops', 'data_health_rejections_24h']);
        $this->assertSame([], LayoutValidator::validate($monitor->layout)['errors']);

        DB::enableQueryLog();
        $r = MonitorData::build($this->scopeFor($monitor, null), $monitor->layout, true);
        $queries = DB::getQueryLog();

        $this->assertSame([], $queries);
        $this->assertSame('ok', $this->payload($r, 'data_health_silent_shops')['state']);
        $this->assertSame('14', $this->payload($r, 'data_health_rejections_24h')['value']);
    }

    public function test_a_clean_feed_shows_the_all_clear_row(): void
    {
        $monitor = $this->customMonitor(['data_health_silent_shops']);
        // No history at all: nobody can be judged.
        $r = MonitorData::build($this->scopeFor($monitor, null));
        $this->assertSame('empty', $this->payload($r, 'data_health_silent_shops')['state']);
    }
}
