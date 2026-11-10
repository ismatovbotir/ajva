<?php

namespace Tests\Feature;

use App\Livewire\Monitoring\Tasks;
use App\Models\User;
use App\Support\TaskRuns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** Monitoring > Tasks: admin-only board of scheduled commands, scheduler heartbeat, queue and 1C sync. */
class MonitoringTasksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
    }

    private function logRun(string $name, string $status, string $at, ?string $note = null): void
    {
        DB::table('task_runs')->insert([
            'name' => $name, 'started_at' => $at, 'finished_at' => $at, 'status' => $status, 'note' => $note, 'duration_ms' => 1500,
        ]);
    }

    public function test_only_admin_can_open_the_page(): void
    {
        $this->actingAs(User::factory()->create())->get('/monitoring/tasks')->assertOk()->assertSee('Tasks');
        $this->actingAs(User::factory()->operator()->create())->get('/monitoring/tasks')->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->get('/monitoring/tasks')->assertRedirect();
    }

    public function test_nav_group_is_for_admin_only(): void
    {
        $this->actingAs(User::factory()->create())->get('/')->assertSee(route('monitoring.tasks'), false);
        $this->actingAs(User::factory()->operator()->create())->get('/')->assertDontSee(route('monitoring.tasks'), false);
    }

    public function test_never_ran_and_scheduler_problem_are_shown(): void
    {
        Livewire::actingAs(User::factory()->create())->test(Tasks::class)
            ->assertSee('orders:auto-generate')
            ->assertSee('telegram:poll')
            ->assertSee('queue:work')
            ->assertSee('Never ran')
            ->assertSee('The scheduler has not reported yet')
            ->assertSeeHtml('data-overall="problems"');
    }

    public function test_working_error_and_not_running_states(): void
    {
        $this->logRun(TaskRuns::HEARTBEAT, 'ok', now()->toDateTimeString(), 'pending=0');
        $this->logRun('orders:auto-generate', 'ok', now()->subMinute()->toDateTimeString());
        $this->logRun('telegram:poll', 'error', now()->toDateTimeString(), 'exit code 1: boom happened');
        $this->logRun('queue:work', 'ok', now()->subHour()->toDateTimeString());

        Livewire::actingAs(User::factory()->create())->test(Tasks::class)
            ->assertSee('Working')
            ->assertSee('boom happened')
            ->assertSee('Not running')
            ->assertSee('1.5 s')
            ->assertSeeHtml('data-overall="problems"');
    }

    public function test_all_running_banner(): void
    {
        $now = now()->toDateTimeString();
        $this->logRun(TaskRuns::HEARTBEAT, 'ok', $now, 'pending=0');
        $this->logRun('orders:auto-generate', 'ok', $now);
        $this->logRun('telegram:poll', 'ok', $now);
        $this->logRun('queue:work', 'ok', $now);

        Livewire::actingAs(User::factory()->create())->test(Tasks::class)
            ->assertSeeHtml('data-overall="ok"')
            ->assertSee('All systems running');
    }

    public function test_growing_queue_is_flagged(): void
    {
        foreach ([2, 4, 6, 8, 10, 12] as $i => $n) {
            $this->logRun(TaskRuns::HEARTBEAT, 'ok', now()->subMinutes(6 - $i)->toDateTimeString(), 'pending='.$n);
        }

        Livewire::actingAs(User::factory()->create())->test(Tasks::class)->assertSeeHtml('data-queue-growing');
    }

    public function test_record_prunes_old_rows(): void
    {
        for ($i = 0; $i < TaskRuns::KEEP + 5; $i++) {
            TaskRuns::record('demo:task', 'ok');
        }

        $this->assertSame(TaskRuns::KEEP, DB::table('task_runs')->where('name', 'demo:task')->count());
        $this->assertSame('ok', TaskRuns::last('demo:task')->status);
    }

    public function test_failed_jobs_are_listed_and_can_be_retried_or_forgotten(): void
    {
        config(['queue.default' => 'database']);
        $payload = json_encode(['uuid' => 'u-1', 'displayName' => 'App\\Jobs\\SyncOrderTo1C', 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call', 'data' => []]);
        foreach (['aaa-1', 'bbb-2'] as $uuid) {
            DB::table('failed_jobs')->insert(['uuid' => $uuid, 'connection' => 'database', 'queue' => 'default', 'payload' => $payload, 'exception' => "RuntimeException: 1C is down\n#0 trace", 'failed_at' => now()]);
        }

        $page = Livewire::actingAs(User::factory()->create())->test(Tasks::class)
            ->assertSee('SyncOrderTo1C')
            ->assertSee('RuntimeException: 1C is down');

        $page->call('retry', 'aaa-1');
        $this->assertDatabaseMissing('failed_jobs', ['uuid' => 'aaa-1']);
        $this->assertSame(1, DB::table('jobs')->count());

        $page->call('forget', 'bbb-2');
        $this->assertDatabaseMissing('failed_jobs', ['uuid' => 'bbb-2']);
    }

    public function test_actions_are_admin_only(): void
    {
        Livewire::actingAs(User::factory()->operator()->create())->test(Tasks::class)->assertForbidden();
    }

    public function test_sync_strip_shows_hint_when_url_is_missing(): void
    {
        config(['services.onec.orders_url' => '']);

        Livewire::actingAs(User::factory()->create())->test(Tasks::class)->assertSeeHtml('data-sync-url-missing');
    }

    public function test_every_scheduled_command_is_on_the_board(): void
    {
        $names = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->map(fn ($e) => TaskRuns::commandName($e))->filter()->all();

        $this->assertContains('queue:work', $names);
        $this->assertContains('orders:auto-generate', $names);
        $this->assertContains('telegram:poll', $names);

        $page = Livewire::actingAs(User::factory()->create())->test(Tasks::class);
        foreach ($names as $name) {
            $page->assertSee($name);
        }
    }
}
