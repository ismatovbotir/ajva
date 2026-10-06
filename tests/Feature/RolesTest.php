<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Livewire\Auth\Login;
use App\Livewire\Settings\Mcp;
use App\Livewire\Users\Index as UsersIndex;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RolesTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_everything(): void
    {
        $admin = User::factory()->create();

        foreach (['/', '/receipts', '/analytics', '/items', '/monitor', '/settings/mcp', '/settings/users'] as $path) {
            $this->actingAs($admin)->get($path)->assertOk();
        }
    }

    public function test_operator_can_use_the_panel_but_not_settings(): void
    {
        $operator = User::factory()->operator()->create();

        foreach (['/', '/receipts', '/analytics', '/items', '/shops', '/monitor'] as $path) {
            $this->actingAs($operator)->get($path)->assertOk();
        }

        $this->actingAs($operator)->get('/settings/mcp')->assertForbidden();
        $this->actingAs($operator)->get('/settings/users')->assertForbidden();
    }

    public function test_monitor_role_only_reaches_the_monitor_screen(): void
    {
        $monitor = User::factory()->monitor()->create();

        // A monitor-only account sees a sign-out button, not a link to a dashboard it can't open.
        $this->actingAs($monitor)->get('/monitor')
            ->assertOk()
            ->assertSee(route('logout'), false)
            ->assertDontSee('← '.__('Dashboard'), false);

        // Everything else bounces back to the monitor screen.
        foreach (['/', '/receipts', '/analytics', '/items', '/settings/users', '/settings/mcp'] as $path) {
            $this->actingAs($monitor)->get($path)->assertRedirect(route('monitor'));
        }
    }

    public function test_guests_still_go_to_login(): void
    {
        $this->get('/monitor')->assertRedirect('/login');
        $this->get('/settings/users')->assertRedirect('/login');
    }

    public function test_menu_hides_settings_from_operators_and_shows_it_to_admins(): void
    {
        $this->actingAs(User::factory()->operator()->create())->get('/')
            ->assertOk()
            ->assertSee(route('items.index'), false)
            ->assertDontSee(route('users.index'), false)
            ->assertDontSee(route('settings.mcp'), false);

        $this->actingAs(User::factory()->create())->get('/')
            ->assertOk()
            ->assertSee(route('users.index'), false)
            ->assertSee(route('settings.mcp'), false);
    }

    public function test_each_role_lands_on_its_own_home_after_login(): void
    {
        foreach (['operator' => 'dashboard', 'monitor' => 'monitor'] as $state => $home) {
            $user = User::factory()->{$state}()->create();

            Livewire::test(Login::class)
                ->set('email', $user->email)
                ->set('password', 'password')
                ->call('login')
                ->assertRedirect(route($home));

            auth()->logout();
        }
    }

    public function test_settings_components_refuse_non_admins_even_on_livewire_updates(): void
    {
        Livewire::actingAs(User::factory()->operator()->create())->test(UsersIndex::class)->assertForbidden();
        Livewire::actingAs(User::factory()->monitor()->create())->test(Mcp::class)->assertForbidden();
    }

    public function test_users_page_assigns_roles_and_protects_admins(): void
    {
        $admin = User::factory()->create();
        $other = User::factory()->operator()->create();

        $component = Livewire::actingAs($admin)->test(UsersIndex::class);

        // Create a monitor account.
        $component->call('create')
            ->set('name', 'Office TV')
            ->set('email', 'tv@example.com')
            ->set('password', 'secret-pass-1')
            ->set('role', 'monitor')
            ->call('save');
        $this->assertSame(UserRole::Monitor, User::where('email', 'tv@example.com')->first()->role);

        // An invalid role is rejected.
        $component->call('create')
            ->set('name', 'Bad')->set('email', 'bad@example.com')->set('password', 'secret-pass-1')
            ->set('role', 'owner')->call('save')->assertHasErrors('role');

        // Promote the operator.
        $component->call('edit', $other->id)->set('role', 'admin')->call('save');
        $this->assertSame(UserRole::Admin, $other->fresh()->role);

        // You cannot change your own role.
        $component->call('edit', $admin->id)->set('role', 'operator')->call('save')->assertHasErrors('role');
        $this->assertSame(UserRole::Admin, $admin->fresh()->role);
    }

    public function test_admins_can_demote_and_delete_each_other_but_never_themselves(): void
    {
        $actor = User::factory()->create();
        $other = User::factory()->create();   // a second admin
        $component = Livewire::actingAs($actor)->test(UsersIndex::class);

        // With two admins one may be demoted...
        $component->call('edit', $other->id)->set('role', 'operator')->call('save');
        $this->assertSame(UserRole::Operator, $other->fresh()->role);

        // ...and the actor can never delete their own account.
        $component->call('delete', $actor->id)->assertHasErrors('delete');
        $this->assertNotNull($actor->fresh());

        // Deleting another (non-admin) user works.
        $component->call('delete', $other->id);
        $this->assertNull($other->fresh());
    }
}
