<?php

namespace Tests\Feature;

use App\Livewire\Auth\Login;
use App\Livewire\Settings\Monitors;
use App\Livewire\Settings\OrderRules;
use App\Livewire\Users\Index as UsersIndex;
use App\Models\Shop;
use App\Models\User;
use App\Services\OrderTelegram;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Activate / deactivate users: no login, signed out on the next request, no Telegram, guards. */
class UserStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
    }

    public function test_existing_and_new_users_are_active_by_default(): void
    {
        $this->assertTrue(User::factory()->create()->fresh()->is_active);
        $this->assertTrue(User::query()->create(['name' => 'N', 'email' => 'n@example.test', 'password' => 'password', 'role' => 'admin'])->fresh()->is_active);
    }

    public function test_an_inactive_user_cannot_log_in_and_is_told_why(): void
    {
        $user = User::factory()->create(['email' => 'off@example.test', 'password' => 'secret-pass-1', 'is_active' => false]);

        Livewire::test(Login::class)->set('email', $user->email)->set('password', 'secret-pass-1')->call('login')
            ->assertHasErrors('email')->assertSee('This account is deactivated');
        $this->assertGuest();

        // A wrong password does not reveal the state.
        Livewire::test(Login::class)->set('email', $user->email)->set('password', 'wrong-password')->call('login')
            ->assertSee('These credentials do not match our records.')->assertDontSee('deactivated');

        $user->update(['is_active' => true]);
        Livewire::test(Login::class)->set('email', $user->email)->set('password', 'secret-pass-1')->call('login')->assertHasNoErrors();
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_signed_in_user_is_logged_out_on_the_next_request_once_deactivated(): void
    {
        $user = User::factory()->create(['password' => 'secret-pass-1']);
        $this->actingAs($user)->get('/')->assertOk();

        $user->update(['is_active' => false]);

        $this->get('/')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_the_dev_auto_login_only_picks_active_users(): void
    {
        $this->app['env'] = 'local';
        config(['app.dev_auto_login' => true, 'app.dev_auto_login_email' => null]);
        $off = User::factory()->create(['is_active' => false]);
        $on = User::factory()->create();

        Livewire::test(Login::class);

        $this->assertAuthenticatedAs($on);
        $this->assertNotSame($off->id, auth()->id());
    }

    public function test_the_admin_deactivates_and_activates_but_not_himself_or_the_last_active_admin(): void
    {
        $admin = User::factory()->create();
        $operator = User::factory()->operator()->create();
        $component = Livewire::actingAs($admin)->test(UsersIndex::class);

        $component->call('setActive', $operator->id, false);
        $this->assertFalse($operator->fresh()->is_active);
        $component->assertSee('Inactive');
        $component->call('setActive', $operator->id, true);
        $this->assertTrue($operator->fresh()->is_active);

        $component->call('setActive', $admin->id, false)->assertHasErrors('status');
        $this->assertTrue($admin->fresh()->is_active);

        // A second admin: the first may be deactivated, then the last active one may not.
        $other = User::factory()->create();
        $component->call('setActive', $other->id, false);
        $this->assertFalse($other->fresh()->is_active);
        $third = User::factory()->create();
        Livewire::actingAs($third)->test(UsersIndex::class)->call('setActive', $admin->id, false);
        $this->assertFalse($admin->fresh()->is_active);
        Livewire::actingAs($third)->test(UsersIndex::class)->call('setActive', $third->id, false)->assertHasErrors('status');
        $this->assertTrue($third->fresh()->is_active);
    }

    public function test_only_an_admin_can_change_the_status(): void
    {
        $operator = User::factory()->operator()->create();

        Livewire::actingAs($operator)->test(UsersIndex::class)->assertForbidden();
    }

    public function test_the_list_and_the_modal_show_the_status_and_buttons(): void
    {
        $admin = User::factory()->create();
        $off = User::factory()->operator()->create(['name' => 'Sleeper', 'is_active' => false]);

        Livewire::actingAs($admin)->test(UsersIndex::class)
            ->assertSee('Sleeper')->assertSee('Inactive')->assertSee('Activate')
            ->call('edit', $off->id)->assertSeeHtml('data-user-status');
    }

    public function test_inactive_users_are_not_responsible_and_get_no_telegram(): void
    {
        $shop = Shop::factory()->create();
        $on = User::factory()->operator()->create(['telegram_chat_id' => '1']);
        $off = User::factory()->operator()->create(['telegram_chat_id' => '2', 'is_active' => false]);
        $shop->users()->attach([$on->id, $off->id]);

        $this->assertSame([$on->id], OrderTelegram::responsible($shop->id)->pluck('id')->all());
        $this->assertSame([$on->id], OrderTelegram::recipients($shop->id)->pluck('id')->all());
        $this->assertSame([$on->id], OrderTelegram::responsibleByShop([$shop->id])[$shop->id]->pluck('id')->all());
    }

    public function test_the_order_rules_page_says_inactive_users_are_skipped(): void
    {
        $shop = Shop::factory()->create();
        \App\Models\ItemOrderRule::query()->create(['shop_id' => $shop->id, 'item_id' => \App\Models\Item::factory()->create()->id, 'min' => 1, 'max' => 5]);

        Livewire::actingAs(User::factory()->create())->test(OrderRules::class)->assertSee('Inactive users are skipped');
    }

    public function test_inactive_users_cannot_be_added_to_a_monitor_but_are_marked_when_already_there(): void
    {
        $off = User::factory()->operator()->create(['name' => 'Sleepy Operator', 'is_active' => false]);
        $on = User::factory()->operator()->create(['name' => 'Sleepy Active']);

        Livewire::actingAs(User::factory()->create())->test(Monitors::class)
            ->call('create')->set('userSearch', 'Sleepy')->assertSee('Sleepy Active')->assertDontSee('Sleepy Operator');
    }
}
