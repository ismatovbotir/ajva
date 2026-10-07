<?php

namespace Tests\Feature;

use App\Enums\MonitorType;
use App\Livewire\Settings\Monitors as MonitorsSettings;
use App\Livewire\Settings\Warehouse as WarehouseSettings;
use App\Models\Monitor;
use App\Models\Shop;
use App\Models\User;
use App\Support\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class MonitorSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_pages_are_admin_only(): void
    {
        $paths = ['/settings/monitors', '/settings/warehouse', '/settings/monitor'];
        foreach ($paths as $path) {
            $this->get($path)->assertRedirect('/login');
        }
        foreach ($paths as $path) {
            $this->actingAs(User::factory()->operator()->create())->get($path)->assertForbidden();
        }

        $admin = User::factory()->create();
        $this->actingAs($admin)->get('/settings/monitors')->assertOk()->assertSee(__('New monitor'));
        $this->actingAs($admin)->get('/settings/warehouse')->assertOk();
        // The old URL and route name keep working.
        $this->actingAs($admin)->get('/settings/monitor')->assertRedirect('/settings/monitors');
        $this->assertSame(url('/settings/monitor'), route('settings.monitor'));

        // Livewire updates re-check the role (boot guard).
        Livewire::actingAs(User::factory()->operator()->create())->test(MonitorsSettings::class)->assertForbidden();
        Livewire::actingAs(User::factory()->monitor()->create())->test(WarehouseSettings::class)->assertForbidden();
    }

    public function test_create_edit_and_delete_a_monitor(): void
    {
        $admin = User::factory()->create();
        $a = Shop::factory()->create();
        $b = Shop::factory()->create();

        $component = Livewire::actingAs($admin)->test(MonitorsSettings::class)
            ->call('create')
            ->set('name', 'Boss TV')
            ->set('type', 'warehouse')
            ->set('shopIds', [(string) $a->id])
            ->call('save')
            ->assertHasNoErrors();

        $monitor = Monitor::query()->firstOrFail();
        $this->assertSame('Boss TV', $monitor->name);
        $this->assertSame(MonitorType::Warehouse, $monitor->type);
        $this->assertTrue($monitor->enabled);          // default on
        $this->assertFalse($monitor->show_profit);     // default off
        $this->assertNull($monitor->token);
        $this->assertSame([$a->id], $monitor->shops()->pluck('shops.id')->all());

        $component->call('edit', $monitor->id)
            ->assertSet('name', 'Boss TV')
            ->set('name', 'Lobby TV')
            ->set('type', 'receipts')
            ->set('shopIds', [])                       // empty = all shops
            ->set('enabled', false)
            ->set('showProfit', true)
            ->call('save');

        $monitor->refresh();
        $this->assertSame('Lobby TV', $monitor->name);
        $this->assertSame(MonitorType::Receipts, $monitor->type);
        $this->assertFalse($monitor->enabled);
        $this->assertTrue($monitor->show_profit);
        $this->assertSame(0, $monitor->shops()->count());
        $this->assertNull($monitor->shopIds());

        $component->assertSee('Lobby TV')->assertSee(__('All shops'));

        $component->call('delete', $monitor->id);
        $this->assertSame(0, Monitor::query()->count());
        $this->assertSame(0, DB::table('monitor_shop')->count());
        $this->assertTrue(Shop::query()->whereKey([$a->id, $b->id])->count() === 2);
    }

    public function test_validation_rejects_bad_input_and_the_list_summarises_shops(): void
    {
        $admin = User::factory()->create();
        $shops = Shop::factory()->count(3)->create();

        Livewire::actingAs($admin)->test(MonitorsSettings::class)
            ->call('create')
            ->set('name', '')
            ->set('type', 'nope')
            ->set('shopIds', ['9999'])
            ->call('save')
            ->assertHasErrors(['name', 'type', 'shopIds.0']);

        $this->assertSame(0, Monitor::query()->count());

        $monitor = Monitor::factory()->create();
        $monitor->shops()->sync($shops->pluck('id'));
        $names = $shops->pluck('name')->sort()->values();
        Livewire::actingAs($admin)->test(MonitorsSettings::class)
            ->assertSee($names[0].', '.$names[1].' +1');
    }

    public function test_select_all_and_clear_shops(): void
    {
        Shop::factory()->count(2)->create();

        Livewire::actingAs(User::factory()->create())->test(MonitorsSettings::class)
            ->call('create')
            ->call('selectAllShops')
            ->assertCount('shopIds', 2)
            ->call('clearShops')
            ->assertSet('shopIds', []);
    }

    public function test_generate_regenerate_link_copy_and_new_window_button(): void
    {
        $admin = User::factory()->create();
        $monitor = Monitor::factory()->create();

        $component = Livewire::actingAs($admin)->test(MonitorsSettings::class)->assertSee(__('Generate link'));
        $component->call('generateLink', $monitor->id);

        $first = $monitor->fresh()->token;
        $this->assertTrue(\Illuminate\Support\Str::isUuid($first));

        $link = rtrim((string) config('app.url'), '/').'/monitor/'.$first;
        $html = $component->assertSee(__('Regenerate link'))->assertSee(__('Copy'))->html();
        $this->assertMatchesRegularExpression('~<a href="'.preg_quote($link, '~').'"[^>]*target="_blank"[^>]*rel="noopener noreferrer"~s', $html);
        $this->assertStringContainsString(__('Open in new window'), $html);
        $this->assertStringContainsString('wire:confirm', $html);

        $component->call('generateLink', $monitor->id);
        $this->assertNotSame($first, $monitor->fresh()->token);
        $this->get('/monitor/'.$first)->assertNotFound();
    }

    public function test_the_link_is_built_from_app_url_and_warns_when_it_is_local(): void
    {
        $admin = User::factory()->create();
        $token = Monitor::factory()->withLink()->create()->token;
        $warning = __('APP_URL points to localhost, so this link will not work on the TV. Set APP_URL to the public address of this server in .env and clear the config cache.');

        // A real public APP_URL (with a trailing slash) gives exactly APP_URL + /monitor/{token}.
        config(['app.url' => 'https://crm.example.uz/']);
        Livewire::actingAs($admin)->test(MonitorsSettings::class)
            ->assertSee('https://crm.example.uz/monitor/'.$token)
            ->assertDontSee($warning);

        // A localhost APP_URL still builds the link from it, plus a visible warning.
        config(['app.url' => 'http://localhost']);
        Livewire::actingAs($admin)->test(MonitorsSettings::class)
            ->assertSee('http://localhost/monitor/'.$token)
            ->assertSee($warning);
    }

    public function test_the_modal_keeps_the_public_profit_warning_and_shop_notes(): void
    {
        Livewire::actingAs(User::factory()->create())->test(MonitorsSettings::class)
            ->call('create')
            ->assertSee(__('Leave empty to show all shops.'))
            ->assertSee(__('Show profit on the public screen'))
            ->assertSee(__('Anyone with a public link can see the sales.'))
            ->assertDontSee(__('The main warehouse is excluded automatically.'))
            ->set('type', 'warehouse')
            ->assertSee(__('The main warehouse is excluded automatically.'));
    }

    public function test_old_single_public_monitor_is_converted_keeping_its_link(): void
    {
        DB::table('monitors')->delete();
        DB::table('settings')->insert([
            'key' => 'monitor',
            'value' => json_encode(['enabled' => true, 'token' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', 'show_profit' => true, 'token_created_at' => '2026-10-01 10:00:00']),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_10_07_100000_create_monitors_table.php');
        $migration->down();
        $migration->up();

        $monitor = Monitor::query()->firstOrFail();
        $this->assertSame('Office monitor', $monitor->name);
        $this->assertSame(MonitorType::Executive, $monitor->type);
        $this->assertSame('aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', $monitor->token);
        $this->assertTrue($monitor->enabled);
        $this->assertTrue($monitor->show_profit);
        $this->assertNull($monitor->shopIds());
        $this->assertFalse(DB::table('settings')->where('key', 'monitor')->exists());

        $this->get('/monitor/aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee')->assertOk();
    }

    public function test_conversion_without_an_old_row_or_token_creates_nothing(): void
    {
        $migration = require database_path('migrations/2026_10_07_100000_create_monitors_table.php');

        DB::table('settings')->insert(['key' => 'monitor', 'value' => json_encode(['enabled' => false, 'token' => null]), 'created_at' => now(), 'updated_at' => now()]);
        $migration->down();
        $migration->up();
        $this->assertSame(0, Monitor::query()->count());
        $this->assertFalse(DB::table('settings')->where('key', 'monitor')->exists());

        $migration->down();
        $migration->up();
        $this->assertSame(0, Monitor::query()->count());
    }

    public function test_warehouse_setting_save_and_read(): void
    {
        $admin = User::factory()->create();
        $main = Shop::factory()->create(['name' => 'Central']);
        $other = Shop::factory()->create();

        $this->assertNull(Warehouse::shopId());
        $this->assertFalse(Warehouse::isConfigured());
        $this->actingAs($admin)->get('/settings/warehouse')->assertOk()
            ->assertSee(__('The main warehouse is not set yet. Warehouse monitors will show every selected shop, including the warehouse itself, until you choose it here.'));

        Livewire::actingAs($admin)->test(WarehouseSettings::class)
            ->set('shopId', (string) $main->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($main->id, Warehouse::shopId());
        $this->assertSame('Central', Warehouse::shop()->name);
        $this->assertTrue(Warehouse::isConfigured());
        $this->assertTrue(Warehouse::isWarehouse($main->id));
        $this->assertFalse(Warehouse::isWarehouse($other->id));
        $this->assertFalse(Warehouse::isWarehouse(null));
        $this->assertSame(['shop_id' => $main->id], json_decode(DB::table('settings')->where('key', 'warehouse')->value('value'), true));

        // The form is prefilled; invalid shops are rejected; "Not set" clears it.
        Livewire::actingAs($admin)->test(WarehouseSettings::class)
            ->assertSet('shopId', (string) $main->id)
            ->set('shopId', '9999')->call('save')->assertHasErrors('shopId')
            ->set('shopId', '')->call('save')->assertHasNoErrors();
        $this->assertNull(Warehouse::shopId());
        $this->assertFalse(Warehouse::isConfigured());
    }
}
