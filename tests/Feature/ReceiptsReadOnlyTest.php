<?php

namespace Tests\Feature;

use App\Livewire\Receipts\Index;
use App\Livewire\Receipts\Show;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\ReceiptPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ReceiptsReadOnlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_receipts_list_renders_with_no_mutation_actions(): void
    {
        $user = User::factory()->create();
        $receipt = Receipt::factory()->create(['number' => 'RCPT-000001']);

        $response = $this->actingAs($user)
            ->get('/receipts')
            ->assertOk()
            ->assertSeeLivewire(Index::class)
            ->assertSee('RCPT-000001');

        $response->assertDontSeeText(__('Delete'));
        $response->assertDontSeeText(__('Edit'));
    }

    public function test_receipt_show_page_renders_items_and_payments_read_only(): void
    {
        $user = User::factory()->create();
        $receipt = Receipt::factory()->create(['number' => 'RCPT-000002']);
        $receiptItem = ReceiptItem::factory()->create(['receipt_id' => $receipt->id]);
        ReceiptPayment::factory()->create(['receipt_id' => $receipt->id, 'payment' => 'cash']);

        $response = $this->actingAs($user)
            ->get("/receipts/{$receipt->id}")
            ->assertOk()
            ->assertSeeLivewire(Show::class)
            ->assertSee('RCPT-000002')
            ->assertSee($receiptItem->item->name)
            ->assertSee('cash');

        $response->assertDontSeeText(__('Delete'));
        $response->assertDontSeeText(__('Edit'));
        $response->assertDontSeeText(__('Save'));
    }

    public function test_analytics_aggregate_selected_day_by_payment_type(): void
    {
        $user = User::factory()->create();
        $today = Receipt::factory()->create(['total' => 100, 'created_at' => now()->setTime(10, 15)]);
        ReceiptPayment::factory()->create(['receipt_id' => $today->id, 'payment' => 'cash', 'value' => 100]);
        $other = Receipt::factory()->create(['total' => 555, 'created_at' => now()->subDay()->setTime(9, 0)]);
        ReceiptPayment::factory()->create(['receipt_id' => $other->id, 'payment' => 'uzcard', 'value' => 555]);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->assertSet('date', now()->toDateString())
            ->assertViewHas('grandTotal', 100.0)
            ->assertViewHas('chart', fn ($c) => count($c['lines']) === 1 && count($c['lines'][0]['dots']) === 1 && $c['ticks'][4]['label'] === '100')
            ->assertViewHas('paymentTypes', ['cash'])
            ->set('date', now()->subDay()->toDateString())
            ->assertViewHas('grandTotal', 555.0)
            ->assertViewHas('paymentTypes', ['uzcard']);
    }

    public function test_guest_cannot_access_receipts(): void
    {
        $this->get('/receipts')->assertRedirect('/login');
    }
}
