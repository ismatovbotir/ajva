<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConfirmModalTest extends TestCase
{
    use RefreshDatabase;

    public function test_layout_renders_the_confirm_modal_and_keeps_wire_confirm_triggers(): void
    {
        $me = User::factory()->create();
        User::factory()->create(['name' => 'Other Person']);

        $this->actingAs($me)->get(route('users.index'))
            ->assertOk()
            ->assertSee('id="confirm-modal"', false)
            ->assertSee('role="alertdialog"', false)
            ->assertSee('data-confirm-ok', false)
            ->assertSee('data-confirm-cancel', false)
            ->assertSee('wire:confirm=', false);
    }

    public function test_confirm_modal_is_rendered_once_per_page(): void
    {
        $html = $this->actingAs(User::factory()->create())->get(route('users.index'))->getContent();

        $this->assertSame(1, substr_count($html, 'id="confirm-modal"'));
    }
}
