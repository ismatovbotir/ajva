<?php

namespace Tests\Feature;

use App\Livewire\AjvaAI\Index;
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

    public function test_tool_call_loop_runs_a_project_tool_and_returns_the_final_answer(): void
    {
        config(['services.gemini.key' => 'test-key', 'services.gemini.model' => 'gemini-test']);
        Shop::factory()->create(['name' => 'Chilonzor']);

        Http::fakeSequence()
            ->push(['candidates' => [['content' => ['role' => 'model', 'parts' => [['functionCall' => ['name' => 'list_shops', 'args' => new \stdClass]]]]]]])
            ->push(['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'You have **1** shop: Chilonzor.']]]]]]);

        $component = Livewire::actingAs($this->admin())->test(Index::class)
            ->set('question', 'How many shops do we have?')
            ->call('ask')
            ->assertSet('error', null)
            ->assertSee('Chilonzor');

        $this->assertSame('model', $component->get('messages')[1]['role']);

        // The tool result went back to Gemini as a functionResponse, and the key travels in a header, not the URL.
        $second = Http::recorded()[1][0];
        $this->assertStringContainsString('gemini-test:generateContent', $second->url());
        $this->assertStringNotContainsString('test-key', $second->url());
        $this->assertSame('test-key', $second->header('x-goog-api-key')[0]);
        $this->assertStringContainsString('Chilonzor', json_encode($second->data()['contents']));
        // Tools are declared, with schema keywords Gemini rejects stripped.
        $declared = json_encode($second->data()['tools']);
        $this->assertStringContainsString('receipt_analytics', $declared);
        $this->assertStringNotContainsString('"pattern"', $declared);
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
