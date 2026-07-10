<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Pos\Index;
use App\Models\Pos;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PosTokenGenerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_generating_a_token_shows_the_plaintext_once_and_stores_only_its_hash(): void
    {
        $user = User::factory()->create();
        $pos = Pos::factory()->create(['name' => 'Till 1']);

        $this->assertNull($pos->api_token_hash);

        $component = Livewire::actingAs($user)
            ->test(Index::class)
            ->call('generateToken', $pos->id);

        $component->assertSet('showTokenModal', true);

        $plaintext = $component->get('generatedToken');
        $this->assertNotEmpty($plaintext);

        $pos->refresh();
        $this->assertNotNull($pos->api_token_hash);
        $this->assertSame(hash('sha256', $plaintext), $pos->api_token_hash);

        $component->assertSee($plaintext);

        // Closing the modal clears the plaintext from component state — it
        // must never be shown again after this.
        $component->call('closeTokenModal');
        $component->assertSet('showTokenModal', false);
        $component->assertSet('generatedToken', null);
    }

    public function test_the_generated_token_authenticates_against_the_receipts_endpoint(): void
    {
        $user = User::factory()->create();
        $pos = Pos::factory()->create();

        $plaintext = Livewire::actingAs($user)
            ->test(Index::class)
            ->call('generateToken', $pos->id)
            ->get('generatedToken');

        $response = $this
            ->withHeader('Authorization', 'Bearer '.$plaintext)
            ->postJson('/api/receipts', [
                'number' => 'A-1',
                'total' => 100,
                'items' => [],
            ]);

        // Auth passes (no 401); the empty items array fails the "min:1"
        // validation rule instead, proving the token itself was accepted.
        $response->assertStatus(422);
        $response->assertJsonMissingValidationErrors(['number', 'total']);
    }
}
