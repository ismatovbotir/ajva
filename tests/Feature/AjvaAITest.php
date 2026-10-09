<?php

namespace Tests\Feature;

use App\Livewire\AjvaAI\Index;
use App\Models\Receipt;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class AjvaAITest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create();
    }

    public function test_page_is_admin_only_and_in_the_menu(): void
    {
        $this->actingAs($this->admin())->get(route('ajva-ai.index'))->assertOk()->assertSee('AjvaAI');

        $operator = User::factory()->create(['role' => 'operator']);
        $this->actingAs($operator)->get(route('ajva-ai.index'))->assertForbidden();
    }

    public function test_without_a_key_it_shows_a_setup_hint_and_never_calls_gemini(): void
    {
        config(['services.gemini.key' => null]);
        Http::fake();

        Livewire::actingAs($this->admin())->test(Index::class)
            ->set('question', 'hello')
            ->call('ask')
            ->assertSee(__('AjvaAI is not configured: set GEMINI_API_KEY in .env.'));

        Http::assertNothingSent();
    }

    public function test_gemini_plans_the_tools_but_never_receives_the_data(): void
    {
        config(['services.gemini.key' => 'test-key', 'services.gemini.model' => 'gemini-test']);
        $shop = Shop::factory()->create(['name' => 'Secret Shop Name']);
        Receipt::factory()->count(12)->create(['shop_id' => $shop->id, 'active' => true, 'sell' => true, 'total' => 123456, 'created_at' => now()]);

        Http::fakeSequence()
            ->push(['candidates' => [['content' => ['role' => 'model', 'parts' => [
                ['text' => 'Running the sales summary for that shop.'],
                ['functionCall' => ['name' => 'sales_summary', 'args' => ['shop_name' => 'secret']]],
            ]]]]])
            ->push(['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'Which period do you mean?']]]]]]);

        $component = Livewire::actingAs($this->admin())->test(Index::class)
            ->set('question', 'How are sales in secret shop?')
            ->call('ask')
            ->assertSet('error', null)
            ->assertSee('Running the sales summary')
            ->assertSee('Secret Shop Name')   // the app ran the tool and shows the figures itself
            ->assertSee('1 481 472');

        // A follow-up goes to Gemini with the whole history; none of the data may be in it.
        $component->set('question', 'and last week?')->call('ask')->assertSee('Which period do you mean?');

        $this->assertCount(2, Http::recorded());
        $second = Http::recorded()[1][0];
        $body = json_encode($second->data(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('Secret Shop Name', $body);
        $this->assertStringNotContainsString('123456', $body);
        $this->assertStringNotContainsString('1481472', $body);
        $this->assertStringContainsString('executed locally', $body);
        $this->assertStringContainsString('gemini-test:generateContent', $second->url());
        $this->assertStringNotContainsString('test-key', $second->url());
        $this->assertSame('test-key', $second->header('x-goog-api-key')[0]);

        // Tools are declared by name, shops by name (no ids), unsupported schema keywords stripped.
        $declared = json_encode($second->data()['tools']);
        $this->assertStringContainsString('receipt_analytics', $declared);
        $this->assertStringContainsString('shop_name', $declared);
        $this->assertStringNotContainsString('"shop_id"', $declared);
        $this->assertStringNotContainsString('"pattern"', $declared);
    }

    public function test_local_advisor_flags_heavy_discounts_without_any_ai(): void
    {
        $notes = \App\Support\LocalAdvisor::notes('receipt_analytics', ['report' => ['kpi' => [
            'sale_count' => 50, 'sale_sum' => 1000.0, 'discount_rate' => 12.0, 'refund_sum' => 0.0, 'cancelled_count' => 0, 'all_count' => 50,
        ], 'big' => ['cut' => null], 'relations' => ['pairs' => []]]]);

        $this->assertSame('warn', $notes[0]['level']);
        $this->assertStringContainsString('12', $notes[0]['text']);
    }

    public function test_with_a_real_queue_the_page_returns_at_once_and_polls_until_the_worker_is_done(): void
    {
        config(['services.gemini.key' => 'test-key']);
        \Illuminate\Support\Facades\Queue::fake();
        Http::fake();

        $component = Livewire::actingAs($this->admin())->test(Index::class)
            ->set('question', 'sales yesterday?')
            ->call('ask')
            ->assertSeeHtml('wire:poll.2s="checkRun"');      // thinking panel + polling, no waiting in the request

        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\RunAjvaQuestion::class);
        Http::assertNothingSent();                                  // the AI call belongs to the worker
        $runId = $component->get('runId');
        $this->assertNotSame('', $runId);

        // Second question while one is running is ignored.
        $component->set('question', 'another')->call('ask');
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\RunAjvaQuestion::class, 1);

        // Still pending: polling changes nothing. Then the worker finishes.
        $component->call('checkRun')->assertSet('runId', $runId);
        \Illuminate\Support\Facades\Cache::put(\App\Jobs\RunAjvaQuestion::key((int) auth()->id(), $runId), [
            'status' => 'done',
            'contents' => [['role' => 'user', 'parts' => [['text' => 'sales yesterday?']]]],
            'message' => ['role' => 'model', 'text' => 'Done answering.', 'sections' => []],
        ], 600);

        $component->call('checkRun')->assertSet('runId', '')->assertSee('Done answering.');
    }

    public function test_a_lost_worker_ends_with_a_clear_message_instead_of_waiting_forever(): void
    {
        config(['services.gemini.key' => 'test-key']);
        \Illuminate\Support\Facades\Queue::fake();

        $component = Livewire::actingAs($this->admin())->test(Index::class)->set('question', 'hi')->call('ask');
        $this->travel(5)->minutes();

        $component->call('checkRun')->assertSet('runId', '')->assertSee('queue:work');
    }

    public function test_a_job_killed_by_the_worker_reports_failure_to_the_page(): void
    {
        $job = new \App\Jobs\RunAjvaQuestion(7, 'run1', []);
        $job->failed(new \RuntimeException('timeout'));

        $this->assertSame('failed', \Illuminate\Support\Facades\Cache::get(\App\Jobs\RunAjvaQuestion::key(7, 'run1'))['status']);
    }

    public function test_rate_limit_is_reported_in_plain_words(): void
    {
        config(['services.gemini.key' => 'test-key']);
        Http::fake(['*' => Http::response(['error' => ['message' => 'quota']], 429)]);

        Livewire::actingAs($this->admin())->test(Index::class)
            ->set('question', 'hi')
            ->call('ask')
            ->assertSee(__('The free AI limit is reached for now. Wait a minute and try again.'));
    }
}
