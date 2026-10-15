<?php

namespace Tests\Feature;

use App\Enums\MonitorType;
use App\Livewire\Dashboard;
use App\Livewire\MonitorList;
use App\Livewire\MonitorScreen;
use App\Livewire\Settings\Monitors as MonitorsSettings;
use App\Models\Monitor;
use App\Models\Shop;
use App\Models\User;
use App\Monitor\LayoutValidator;
use App\Monitor\Presets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Concerns\BuildsCustomMonitors;
use Tests\TestCase;

/** Who may open a custom monitor, templates, publishing, duplication and the settings form. */
class CustomMonitorAccessTest extends TestCase
{
    use BuildsCustomMonitors;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-07 15:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, User> */
    private function people(): array
    {
        return [
            'admin' => User::factory()->create(),
            'operator' => User::factory()->operator()->create(),
            'other_operator' => User::factory()->operator()->create(),
            'monitor' => User::factory()->monitor()->create(),
        ];
    }

    private function canOpen(Monitor $monitor, User $user): bool
    {
        return Monitor::openableBy($user)->contains('id', $monitor->id);
    }

    public function test_empty_roles_and_users_means_every_role_that_may_open_monitors(): void
    {
        $monitor = $this->customMonitor(['sales_revenue']);

        foreach ($this->people() as $name => $user) {
            $this->assertTrue($this->canOpen($monitor, $user), $name);
            $this->actingAs($user)->get(route('monitors.show', $monitor))->assertOk();
        }
    }

    public function test_the_role_and_user_access_matrix(): void
    {
        $p = $this->people();
        $byRole = $this->customMonitor(['sales_revenue'], ['name' => 'By role', 'roles' => ['operator']]);
        $byUser = $this->customMonitor(['sales_revenue'], ['name' => 'By user']);
        $byUser->users()->sync([$p['monitor']->id]);
        $both = $this->customMonitor(['sales_revenue'], ['name' => 'Both', 'roles' => ['operator']]);
        $both->users()->sync([$p['monitor']->id]);
        $adminOnly = $this->customMonitor(['sales_revenue'], ['name' => 'Nobody', 'roles' => ['monitor']]);
        $adminOnly->users()->sync([$p['operator']->id]);

        $expected = [
            //             admin  operator other_operator monitor
            'By role' => [$byRole, [true, true, true, false]],
            'By user' => [$byUser, [true, false, false, true]],
            'Both' => [$both, [true, true, true, true]],
            'Nobody' => [$adminOnly, [true, true, false, true]],
        ];

        foreach ($expected as $label => [$monitor, $allowed]) {
            foreach (array_values($p) as $i => $user) {
                $this->assertSame($allowed[$i], $this->canOpen($monitor, $user), "{$label} / ".array_keys($p)[$i]);

                $response = $this->actingAs($user)->get(route('monitors.show', $monitor));
                $allowed[$i] ? $response->assertOk() : $response->assertNotFound();
            }
        }
    }

    public function test_the_listing_only_shows_monitors_the_user_may_open(): void
    {
        $p = $this->people();
        $this->customMonitor(['sales_revenue'], ['name' => 'Public one']);
        $this->customMonitor(['sales_revenue'], ['name' => 'Operators only', 'roles' => ['operator']]);

        Livewire::actingAs($p['monitor'])->test(MonitorList::class)->assertRedirect(route('monitors.show', Monitor::query()->where('name', 'Public one')->first()));
        Livewire::actingAs($p['operator'])->test(MonitorList::class)->assertSee('Public one')->assertSee('Operators only');
    }

    public function test_disabled_monitors_stay_404_for_non_admins_even_when_allowed(): void
    {
        $p = $this->people();
        $monitor = $this->customMonitor(['sales_revenue'], ['enabled' => false, 'roles' => ['operator']]);

        $this->actingAs($p['operator'])->get(route('monitors.show', $monitor))->assertNotFound();
        $this->actingAs($p['admin'])->get(route('monitors.show', $monitor))->assertOk();
    }

    public function test_the_component_rechecks_access_on_every_request(): void
    {
        $p = $this->people();
        $monitor = $this->customMonitor(['sales_revenue']);
        $component = Livewire::actingAs($p['operator'])->test(MonitorScreen::class, ['monitor' => $monitor])->assertOk();

        $monitor->update(['roles' => ['monitor']]);   // the operator loses access while the TV page is open

        $component->call('$refresh')->assertNotFound();
    }

    public function test_shop_scoping_still_applies_on_top_of_access(): void
    {
        $a = Shop::factory()->create(['name' => 'Alpha']);
        $b = Shop::factory()->create(['name' => 'Beta']);
        $monitor = $this->customMonitor(['shop_leaderboard'], [], [$a->id]);
        $operator = User::factory()->operator()->create();
        $operator->shops()->sync([$b->id]);

        $this->assertFalse($this->canOpen($monitor, $operator), 'no overlap between the monitor shops and the operator shops');
        $operator->shops()->sync([$a->id, $b->id]);
        $this->assertTrue($this->canOpen($monitor, $operator));
    }

    // ---------------------------------------------------------------- templates

    public function test_a_template_is_not_a_screen(): void
    {
        $template = $this->customMonitor(['sales_revenue'], ['is_template' => true, 'token' => '11111111-2222-4333-8444-555555555555']);

        $this->assertFalse($this->canOpen($template, User::factory()->create()));
        $this->actingAs(User::factory()->create())->get(route('monitors.show', $template))->assertNotFound();
        $this->get('/monitor/11111111-2222-4333-8444-555555555555')->assertNotFound();
        $this->assertNull(Monitor::findPublic('11111111-2222-4333-8444-555555555555'));

        Livewire::actingAs(User::factory()->create())->test(MonitorsSettings::class)
            ->call('generateLink', $template->id)->assertStatus(422);
    }

    public function test_create_from_a_built_in_template_copies_the_layout_with_fresh_ids_and_no_link(): void
    {
        $admin = User::factory()->create();

        Livewire::actingAs($admin)->test(MonitorsSettings::class)
            ->call('create')
            ->set('templateSource', 'preset:warehouse')->set('name', 'Night warehouse')
            ->call('save')
            ->assertRedirect();

        $monitor = Monitor::query()->where('name', 'Night warehouse')->firstOrFail();
        $this->assertSame(MonitorType::Custom, $monitor->type);
        $this->assertNull($monitor->token, 'no public link until it is published');
        $this->assertFalse($monitor->is_template);
        $this->assertSame([], LayoutValidator::validate($monitor->layout)['errors']);
        $this->assertEqualsCanonicalizing(
            array_column(Presets::layout('warehouse'), 'key'),
            array_column($monitor->layout, 'key'),
        );
        $this->assertNotContains($monitor->layout[0]['id'], array_column(Presets::layout('warehouse'), 'id'));
    }

    public function test_create_from_a_saved_template_copies_its_layout(): void
    {
        $template = $this->customMonitor(['sales_revenue', 'top_items'], ['is_template' => true, 'name' => 'My template']);
        $admin = User::factory()->create();

        Livewire::actingAs($admin)->test(MonitorsSettings::class)
            ->call('create')
            ->set('templateSource', 'monitor:'.$template->id)->set('name', 'From mine')
            ->call('save');

        $copy = Monitor::query()->where('name', 'From mine')->firstOrFail();
        $this->assertSame(['sales_revenue', 'top_items'], array_column($copy->layout, 'key'));
        $this->assertNotSame(array_column($template->layout, 'id'), array_column($copy->layout, 'id'));
        $this->assertNull($copy->token);
        $this->assertFalse($copy->is_template);
    }

    public function test_a_normal_monitor_cannot_be_used_as_a_template_source(): void
    {
        $notTemplate = $this->customMonitor(['sales_revenue'], ['name' => 'Live']);

        Livewire::actingAs(User::factory()->create())->test(MonitorsSettings::class)
            ->call('create')
            ->set('templateSource', 'monitor:'.$notTemplate->id)->set('name', 'Sneaky')
            ->call('save')
            ->assertHasErrors('templateSource');

        $this->assertFalse(Monitor::query()->where('name', 'Sneaky')->exists());
    }

    public function test_duplicate_copies_layout_shops_and_access_but_not_the_link(): void
    {
        $shop = Shop::factory()->create();
        $user = User::factory()->operator()->create();
        $monitor = $this->customMonitor(['sales_revenue', 'receipt_count'], ['name' => 'Original', 'roles' => ['operator'], 'token' => '99999999-2222-4333-8444-555555555555'], [$shop->id]);
        $monitor->users()->sync([$user->id]);

        Livewire::actingAs(User::factory()->create())->test(MonitorsSettings::class)->call('duplicate', $monitor->id);

        $copy = Monitor::query()->where('id', '!=', $monitor->id)->firstOrFail();
        $this->assertStringContainsString('Original', $copy->name);
        $this->assertNull($copy->token);
        $this->assertSame(['operator'], $copy->roles);
        $this->assertSame([$shop->id], $copy->shops()->pluck('shops.id')->all());
        $this->assertSame([$user->id], $copy->users()->pluck('users.id')->all());
        $this->assertSame(array_column($monitor->layout, 'key'), array_column($copy->layout, 'key'));
        $this->assertNotSame(array_column($monitor->layout, 'id'), array_column($copy->layout, 'id'));
    }

    // ---------------------------------------------------------------- settings form

    public function test_creating_a_custom_monitor_stores_access_and_opens_the_builder(): void
    {
        $shop = Shop::factory()->create();
        $user = User::factory()->operator()->create(['name' => 'Anvar']);

        $component = Livewire::actingAs(User::factory()->create())->test(MonitorsSettings::class)
            ->call('create')
            ->set('name', 'Wall')->set('templateSource', 'preset:blank')->set('description', 'Hall TV')
            ->set('shopIds', [(string) $shop->id])->set('roles', ['monitor'])
            ->set('userSearch', 'Anva')->assertSee('Anvar')->call('addUser', $user->id)
            ->set('isTemplate', false)
            ->call('save');

        $monitor = Monitor::query()->where('name', 'Wall')->firstOrFail();
        $component->assertRedirect(route('settings.monitors.builder', $monitor));
        $this->assertSame(MonitorType::Custom, $monitor->type);
        $this->assertSame('Hall TV', $monitor->description);
        $this->assertSame(['monitor'], $monitor->roles);
        $this->assertSame([$user->id], $monitor->users()->pluck('users.id')->all());
        $this->assertSame([], $monitor->layout, 'the blank template starts empty');
    }

    public function test_every_new_monitor_is_a_custom_one_with_the_constructor_whatever_the_form_says(): void
    {
        Livewire::actingAs(User::factory()->create())->test(MonitorsSettings::class)
            ->call('create')->set('name', 'Exec')->set('templateSource', 'preset:executive')->call('save')->assertRedirect();

        $monitor = Monitor::query()->where('name', 'Exec')->firstOrFail();
        $this->assertSame(MonitorType::Custom, $monitor->type);
        $this->assertNotEmpty($monitor->layout);
        $this->assertNull($monitor->layout_rows);

        $this->expectException(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);
        Livewire::actingAs(User::factory()->create())->test(MonitorsSettings::class)->call('create')->set('type', 'warehouse');
    }

    public function test_a_template_choice_is_required_for_a_new_monitor(): void
    {
        Livewire::actingAs(User::factory()->create())->test(MonitorsSettings::class)
            ->call('create')->set('name', 'X')->set('templateSource', '')->call('save')->assertHasErrors('templateSource');

        $this->assertSame(0, Monitor::query()->count());
    }

    public function test_converting_a_fixed_monitor_keeps_token_shops_access_and_flags(): void
    {
        $shop = Shop::factory()->create();
        $user = User::factory()->operator()->create();
        $token = '12345678-2222-4333-8444-555555555555';

        foreach (['executive' => 'sales_revenue', 'receipts' => 'receipt_count', 'warehouse' => 'wh_pick_list'] as $type => $expectedKey) {
            $monitor = Monitor::factory()->type(MonitorType::from($type))->create([
                'name' => 'Old '.$type, 'token' => $type === 'executive' ? $token : null, 'enabled' => false, 'show_profit' => true, 'roles' => ['monitor'], 'layout_version' => 3,
            ]);
            $monitor->shops()->sync([$shop->id]);
            $monitor->users()->sync([$user->id]);

            Livewire::actingAs(User::factory()->create())->test(MonitorsSettings::class)
                ->call('convert', $monitor->id)->assertRedirect(route('settings.monitors.builder', $monitor));

            $monitor->refresh();
            $this->assertSame(MonitorType::Custom, $monitor->type);
            $this->assertSame('Old '.$type, $monitor->name);
            $this->assertSame($type === 'executive' ? $token : null, $monitor->token);
            $this->assertFalse($monitor->enabled);
            $this->assertTrue($monitor->show_profit);
            $this->assertSame(['monitor'], $monitor->roles);
            $this->assertSame([$shop->id], $monitor->shops()->pluck('shops.id')->all());
            $this->assertSame([$user->id], $monitor->users()->pluck('users.id')->all());
            $this->assertSame(4, $monitor->layout_version);
            $this->assertContains($expectedKey, array_column($monitor->layout, 'key'));
            $this->assertSame([], LayoutValidator::validate($monitor->layout)['errors']);
        }
    }

    public function test_a_custom_monitor_cannot_be_converted_and_only_admins_convert(): void
    {
        $custom = $this->customMonitor(['sales_revenue']);
        Livewire::actingAs(User::factory()->create())->test(MonitorsSettings::class)->call('convert', $custom->id)->assertStatus(422);

        $fixed = Monitor::factory()->type(MonitorType::Executive)->create();
        Livewire::actingAs(User::factory()->operator()->create())->test(MonitorsSettings::class)->assertForbidden();
        $this->assertSame(MonitorType::Executive, $fixed->fresh()->type);
    }

    public function test_the_list_offers_the_constructor_for_every_monitor(): void
    {
        $custom = $this->customMonitor(['sales_revenue'], ['name' => 'Mine']);
        $fixed = Monitor::factory()->type(MonitorType::Receipts)->create(['name' => 'Legacy']);

        Livewire::actingAs(User::factory()->create())->test(MonitorsSettings::class)
            ->assertSeeHtml(route('settings.monitors.builder', $custom))
            ->assertSeeHtml('wire:click="convert('.$fixed->id.')"');
    }

    public function test_roles_are_validated_and_templates_only_exist_for_custom_monitors(): void
    {
        $admin = User::factory()->create();

        Livewire::actingAs($admin)->test(MonitorsSettings::class)
            ->call('create')->set('name', 'X')->set('roles', ['admin'])->call('save')->assertHasErrors('roles.0');

        Livewire::actingAs($admin)->test(MonitorsSettings::class)
            ->call('create')->set('name', 'Exec')->set('isTemplate', true)->call('save');

        // A new monitor is custom, so it CAN be a template; an old fixed screen cannot.
        $this->assertTrue(Monitor::query()->where('name', 'Exec')->firstOrFail()->is_template);

        $fixed = Monitor::factory()->type(MonitorType::Executive)->create(['name' => 'Fixed']);
        Livewire::actingAs($admin)->test(MonitorsSettings::class)->call('edit', $fixed->id)->set('isTemplate', true)->call('save');
        $this->assertFalse($fixed->fresh()->is_template);
    }

    public function test_the_settings_page_stays_admin_only(): void
    {
        $this->actingAs(User::factory()->operator()->create())->get(route('settings.monitors'))->assertForbidden();
        $this->actingAs(User::factory()->create())->get(route('settings.monitors'))->assertOk()->assertSee(__('New monitor'));
    }

    // ---------------------------------------------------------------- publishing

    public function test_publishing_generates_a_link_that_serves_the_screen_and_regenerating_kills_the_old_one(): void
    {
        $monitor = $this->customMonitor(['sales_revenue']);
        $admin = User::factory()->create();

        Livewire::actingAs($admin)->test(MonitorsSettings::class)->call('generateLink', $monitor->id);
        $first = $monitor->fresh()->token;
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $first);
        $this->get('/monitor/'.$first)->assertOk();

        Livewire::actingAs($admin)->test(MonitorsSettings::class)->call('generateLink', $monitor->id);
        $this->get('/monitor/'.$first)->assertNotFound();
        $this->get('/monitor/'.$monitor->fresh()->token)->assertOk();

        $monitor->update(['enabled' => false]);
        $this->get('/monitor/'.$monitor->fresh()->token)->assertNotFound();
    }

    public function test_a_public_screen_re_validates_the_token_on_every_poll(): void
    {
        $monitor = $this->customMonitor(['sales_revenue']);
        $token = $monitor->regenerateToken();
        $component = Livewire::test(MonitorScreen::class, ['token' => $token])->assertOk();

        $monitor->regenerateToken();

        $component->call('$refresh')->assertNotFound();
    }

    // ---------------------------------------------------------------- regression: Dashboard stock value bar

    public function test_dashboard_stock_value_bar_survives_a_zero_maximum(): void
    {
        $shop = Shop::factory()->create();
        \App\Models\Stock::factory()->create(['shop_id' => $shop->id, 'qty' => 5]);   // stock without any cost price: every value is 0

        Livewire::actingAs(User::factory()->create())->test(Dashboard::class)->assertOk();
    }

    public function test_a_zero_maximum_given_as_a_string_falls_back_to_one(): void
    {
        // MariaDB returns SUM() of decimals as the string '0.00', which is truthy in PHP: the old
        // `(float) ($rows->max('value') ?: 1)` kept 0.0 and divided by it. The fixed expression:
        $rows = collect([(object) ['value' => '0.00'], (object) ['value' => '0.00']]);

        $max = (float) $rows->max('value') ?: 1.0;

        $this->assertSame(1.0, $max);
        $this->assertStringContainsString("(float) \$perShop->max('value') ?: 1.0", file_get_contents(app_path('Livewire/Dashboard.php')));
    }
}
