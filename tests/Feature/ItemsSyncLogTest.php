<?php

namespace Tests\Feature;

use App\Jobs\ProcessLocalItemsBatch;
use App\Models\User;
use App\Support\ItemsSyncLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ItemsSyncLogTest extends TestCase
{
    use RefreshDatabase;

    private function local(): static
    {
        return $this->withServerVariables(['REMOTE_ADDR' => '10.1.2.3']);
    }

    private function batch(int $n, int $from = 1): array
    {
        $items = [];
        for ($i = $from; $i < $from + $n; $i++) {
            $items[] = [
                'id' => $i, 'mark' => "M$i", 'name' => "Item $i",
                'qty' => [['shop' => ['id' => 1, 'name' => 'Shop 1'], 'value' => $i]],
            ];
        }

        return $items;
    }

    public function test_batches_of_one_day_share_one_row_and_a_new_day_adds_one(): void
    {
        $this->travelTo(now()->setTime(9, 0));
        $this->local()->postJson('/api/items', $this->batch(3))->assertStatus(202);

        $this->assertSame(1, DB::table('item_sync_days')->count());
        $row = DB::table('item_sync_days')->first();
        $this->assertSame(1, (int) $row->batches);
        $this->assertSame(3, (int) $row->items);
        $first = $row->last_success_at;

        $this->travelTo(now()->setTime(15, 30));
        $this->local()->postJson('/api/items', $this->batch(2, 10))->assertStatus(202);

        $this->assertSame(1, DB::table('item_sync_days')->count());
        $row = DB::table('item_sync_days')->first();
        $this->assertSame(2, (int) $row->batches);
        $this->assertSame(5, (int) $row->items);
        $this->assertNotSame($first, $row->last_success_at);
        $this->assertStringContainsString('15:30', $row->last_success_at);

        $this->travelTo(now()->addDay());
        $this->local()->postJson('/api/items', $this->batch(1))->assertStatus(202);
        $this->assertSame(2, DB::table('item_sync_days')->count());

        // Successes write no error rows.
        $this->assertSame(0, DB::table('item_sync_errors')->count());
        $this->assertSame(0, (int) DB::table('item_sync_days')->sum('error_count'));
    }

    public function test_validation_failure_is_logged_and_still_returns_422(): void
    {
        $bad = $this->batch(2);
        $bad[1]['name'] = null;

        $this->local()->postJson('/api/items', $bad)->assertStatus(422);

        $err = DB::table('item_sync_errors')->first();
        $this->assertNotNull($err);
        $this->assertSame('validation', $err->stage);
        $this->assertSame(2, (int) $err->item_id);
        $this->assertSame(2, (int) $err->batch_size);
        $this->assertSame('10.1.2.3', $err->ip);
        $this->assertStringContainsString('items.1.name', $err->message);
        $this->assertSame(1, (int) DB::table('item_sync_days')->value('error_count'));
        $this->assertNull(DB::table('item_sync_days')->value('last_success_at'));
    }

    public function test_a_job_exception_is_logged(): void
    {
        // A name longer than the column makes the strict-mode style insert fail on MariaDB; here force it with a
        // broken payload (a group id that is not scalar) so the upsert throws on every driver.
        $items = $this->batch(2);
        $items[0]['group'] = ['id' => ['x'], 'name' => 'G'];

        try {
            ProcessLocalItemsBatch::dispatchSync($items);
            $this->fail('The job should have thrown.');
        } catch (\Throwable) {
            // expected
        }

        $err = DB::table('item_sync_errors')->first();
        $this->assertNotNull($err);
        $this->assertSame('job', $err->stage);
        $this->assertSame(2, (int) $err->batch_size);
        $this->assertSame('first item 1', $err->excerpt);
        $this->assertSame(0, (int) DB::table('item_sync_days')->value('batches'));
    }

    public function test_aggregated_skip_row_and_pruning_keep_the_newest(): void
    {
        config(['inventory.item_sync_keep_errors' => 3]);

        ItemsSyncLog::error(ItemsSyncLog::STAGE_SKIPPED, 'Skipped 40 shops not known', null, null, 200);
        $this->assertSame(1, DB::table('item_sync_errors')->count());

        foreach (range(1, 5) as $i) {
            ItemsSyncLog::error(ItemsSyncLog::STAGE_JOB, "boom $i");
        }

        $this->assertSame(3, DB::table('item_sync_errors')->count());
        $this->assertSame(['boom 5', 'boom 4', 'boom 3'], DB::table('item_sync_errors')->orderByDesc('id')->pluck('message')->all());
        // The day counter keeps the full count; pruning only trims the detail rows.
        $this->assertSame(6, (int) DB::table('item_sync_days')->value('error_count'));
    }

    public function test_page_is_admin_only_and_lists_days_and_errors(): void
    {
        $this->local()->postJson('/api/items', $this->batch(1))->assertStatus(202);
        ItemsSyncLog::error(ItemsSyncLog::STAGE_JOB, 'Something broke');

        $this->actingAs(User::factory()->operator()->create())->get(route('monitoring.items-sync'))->assertForbidden();

        $this->actingAs(User::factory()->create())->get(route('monitoring.items-sync'))
            ->assertOk()
            ->assertSee('data-day="'.now()->toDateString().'"', false)
            ->assertSee('Something broke');
    }

    public function test_tasks_page_shows_the_update_time_and_todays_errors(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin)->get(route('monitoring.tasks'))->assertOk()->assertDontSee('data-onec-errors', false);

        $this->travelTo(now()->setTime(11, 42, 7));
        $this->local()->postJson('/api/items', $this->batch(1))->assertStatus(202);
        ItemsSyncLog::error(ItemsSyncLog::STAGE_JOB, 'x');
        ItemsSyncLog::error(ItemsSyncLog::STAGE_JOB, 'y');

        $this->actingAs($admin)->get(route('monitoring.tasks'))
            ->assertOk()
            ->assertSee('11:42')
            ->assertSee('data-onec-errors', false);
    }
}
