<?php

namespace Tests\Feature;

use App\Livewire\Receipts\Index;
use App\Livewire\Receipts\Show;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\ReceiptPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_guest_cannot_access_receipts(): void
    {
        $this->get('/receipts')->assertRedirect('/login');
    }
}
