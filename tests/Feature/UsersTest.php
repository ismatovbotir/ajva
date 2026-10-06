<?php

namespace Tests\Feature;

use App\Livewire\Users\Index;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class UsersTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/settings/users')->assertRedirect(route('login'));
    }

    public function test_page_renders_with_you_badge(): void
    {
        $me = User::factory()->create(['name' => 'Myself']);
        User::factory()->create(['name' => 'Other Person']);

        $this->actingAs($me)->get(route('users.index'))
            ->assertOk()
            ->assertSee('Myself')
            ->assertSee('Other Person')
            ->assertSee(__('You'));
    }

    public function test_create_user_hashes_password_once(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test(Index::class)
            ->call('create')
            ->set('name', 'New Guy')
            ->set('email', 'new@example.com')
            ->set('password', 'secret-pass')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('showModal', false);

        $user = User::where('email', 'new@example.com')->firstOrFail();
        $this->assertSame('New Guy', $user->name);
        $this->assertTrue(Hash::check('secret-pass', $user->password));
    }

    public function test_password_required_on_create_and_min_8(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test(Index::class)
            ->call('create')
            ->set('name', 'X')
            ->set('email', 'x@example.com')
            ->call('save')
            ->assertHasErrors(['password' => 'required'])
            ->set('password', 'short')
            ->call('save')
            ->assertHasErrors(['password' => 'min']);

        $this->assertDatabaseMissing('users', ['email' => 'x@example.com']);
    }

    public function test_edit_without_password_keeps_existing_hash(): void
    {
        $actor = User::factory()->create();
        $target = User::factory()->create(['password' => 'old-password']);
        $hash = $target->fresh()->password;

        Livewire::actingAs($actor)
            ->test(Index::class)
            ->call('edit', $target->id)
            ->assertSet('name', $target->name)
            ->assertSet('password', '')
            ->set('name', 'Renamed')
            ->call('save')
            ->assertHasNoErrors();

        $target->refresh();
        $this->assertSame('Renamed', $target->name);
        $this->assertSame($hash, $target->password);
    }

    public function test_edit_with_password_changes_it(): void
    {
        $actor = User::factory()->create();
        $target = User::factory()->create(['password' => 'old-password']);

        Livewire::actingAs($actor)
            ->test(Index::class)
            ->call('edit', $target->id)
            ->set('password', 'brand-new-pass')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue(Hash::check('brand-new-pass', $target->fresh()->password));
    }

    public function test_edit_with_short_password_fails(): void
    {
        $target = User::factory()->create();

        Livewire::actingAs(User::factory()->create())
            ->test(Index::class)
            ->call('edit', $target->id)
            ->set('password', 'short')
            ->call('save')
            ->assertHasErrors(['password' => 'min']);
    }

    public function test_email_must_be_unique_but_own_email_is_allowed_on_edit(): void
    {
        $actor = User::factory()->create();
        $other = User::factory()->create(['email' => 'taken@example.com']);
        $target = User::factory()->create(['email' => 'mine@example.com']);

        Livewire::actingAs($actor)
            ->test(Index::class)
            ->call('create')
            ->set('name', 'Dup')
            ->set('email', 'taken@example.com')
            ->set('password', 'longenough')
            ->call('save')
            ->assertHasErrors(['email' => 'unique'])
            ->call('edit', $target->id)
            ->set('email', 'taken@example.com')
            ->call('save')
            ->assertHasErrors(['email' => 'unique'])
            ->set('email', 'mine@example.com')
            ->set('name', 'Same email ok')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Same email ok', $target->fresh()->name);
        $this->assertSame('taken@example.com', $other->fresh()->email);
    }

    public function test_search_filters_by_name_and_email(): void
    {
        $actor = User::factory()->create(['name' => 'Actor', 'email' => 'actor@example.com']);
        User::factory()->create(['name' => 'Alice Wonder', 'email' => 'alice@foo.test']);
        User::factory()->create(['name' => 'Bob Builder', 'email' => 'bob@bar.test']);

        Livewire::actingAs($actor)
            ->test(Index::class)
            ->set('search', 'Alice')
            ->assertSee('Alice Wonder')
            ->assertDontSee('Bob Builder')
            ->set('search', 'bar.test')
            ->assertSee('Bob Builder')
            ->assertDontSee('Alice Wonder');
    }

    public function test_can_delete_another_user(): void
    {
        $actor = User::factory()->create();
        $target = User::factory()->create();

        Livewire::actingAs($actor)->test(Index::class)->call('delete', $target->id);

        $this->assertDatabaseMissing('users', ['id' => $target->id]);
        $this->assertDatabaseHas('users', ['id' => $actor->id]);
    }

    public function test_cannot_delete_yourself(): void
    {
        $actor = User::factory()->create();
        User::factory()->create();

        Livewire::actingAs($actor)->test(Index::class)
            ->call('delete', $actor->id)
            ->assertHasErrors('delete');

        $this->assertDatabaseHas('users', ['id' => $actor->id]);
    }

    public function test_cannot_delete_last_remaining_user(): void
    {
        $actor = User::factory()->create();

        Livewire::actingAs($actor)->test(Index::class)
            ->call('delete', 999)
            ->assertHasErrors('delete');

        $this->assertSame(1, User::count());
    }
}
