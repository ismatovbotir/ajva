<?php

namespace Tests\Feature;

use App\Livewire\Shops\Show;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ShopDetailsTest extends TestCase
{
    use RefreshDatabase;

    private function batch(string $shopName): array
    {
        return [[
            'id' => 1, 'mark' => 'M1', 'name' => 'Item 1',
            'group' => ['id' => 1, 'name' => 'Group'],
            'barcode' => ['B1'],
            'qty' => [['shop' => ['id' => 1, 'name' => $shopName], 'value' => 5]],
            'price' => [['price' => ['id' => 2, 'name' => 'Retail'], 'value' => 1000]],
            'order' => [['shop' => ['id' => 1, 'name' => $shopName], 'min' => 1, 'max' => 10]],
        ]];
    }

    public function test_a_1c_sync_never_overwrites_the_area_and_the_map_link(): void
    {
        $shop = Shop::factory()->create(['id' => 1, 'name' => 'Old name']);
        $shop->forceFill(['area_m2' => 120.5, 'map_url' => 'https://yandex.com/maps/-/CCUabc'])->save();

        $this->withServerVariables(['REMOTE_ADDR' => '10.1.2.3'])->postJson('/api/items', $this->batch('New name'))->assertStatus(202);

        $shop->refresh();
        $this->assertSame('New name', $shop->name);                 // 1C owns the name
        $this->assertSame('120.50', (string) $shop->area_m2);       // not touched
        $this->assertSame('https://yandex.com/maps/-/CCUabc', $shop->map_url);
    }

    public function test_a_shop_created_by_the_sync_starts_with_both_fields_empty(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '10.1.2.3'])->postJson('/api/items', $this->batch('Fresh'))->assertStatus(202);

        $shop = Shop::query()->findOrFail(1);
        $this->assertNull($shop->area_m2);
        $this->assertNull($shop->map_url);
    }

    public function test_an_admin_saves_the_area_and_a_yandex_link(): void
    {
        $shop = Shop::factory()->create();

        Livewire::actingAs(User::factory()->create())->test(Show::class, ['shop' => $shop])
            ->call('edit')
            ->set('areaM2', '85,5')
            ->set('mapUrl', 'https://yandex.uz/maps/10335/tashkent/?ll=69.2%2C41.3')
            ->call('save')
            ->assertHasNoErrors();

        $shop->refresh();
        $this->assertSame('85.50', (string) $shop->area_m2);
        $this->assertStringStartsWith('https://yandex.uz/maps/', $shop->map_url);
    }

    public function test_only_a_yandex_https_link_and_a_sane_area_are_accepted(): void
    {
        $shop = Shop::factory()->create();
        $component = Livewire::actingAs(User::factory()->create())->test(Show::class, ['shop' => $shop])->call('edit');

        foreach (['http://yandex.com/maps/1', 'https://example.com/maps', 'https://yandex.com.evil.com/x', 'javascript:alert(1)'] as $bad) {
            $component->set('areaM2', '10')->set('mapUrl', $bad)->call('save')->assertHasErrors('mapUrl');
        }
        $component->set('mapUrl', '')->set('areaM2', '-5')->call('save')->assertHasErrors('areaM2');
        $component->set('areaM2', 'abc')->call('save')->assertHasErrors('areaM2');

        $this->assertNull($shop->fresh()->map_url);
    }

    public function test_clearing_both_fields_stores_null(): void
    {
        $shop = Shop::factory()->create();
        $shop->forceFill(['area_m2' => 50, 'map_url' => 'https://yandex.com/maps/-/x'])->save();

        Livewire::actingAs(User::factory()->create())->test(Show::class, ['shop' => $shop])
            ->call('edit')->set('areaM2', '')->set('mapUrl', '')->call('save')->assertHasNoErrors();

        $this->assertNull($shop->fresh()->area_m2);
        $this->assertNull($shop->fresh()->map_url);
    }

    public function test_disabled_shop_is_saved_and_hidden_from_report_selects(): void
    {
        $shop = Shop::factory()->create(['name' => 'ZZ Closed Shop']);
        $admin = User::factory()->create();

        Livewire::actingAs($admin)->test(Show::class, ['shop' => $shop])
            ->call('edit')->set('isDisabled', true)->call('save')->assertHasNoErrors();
        $this->assertTrue($shop->fresh()->is_disabled);

        Livewire::actingAs($admin)->test(\App\Livewire\Assortment\Index::class)->assertDontSee('ZZ Closed Shop');

        Livewire::actingAs($admin)->test(Show::class, ['shop' => $shop])
            ->call('edit')->set('isDisabled', false)->call('save');
        Livewire::actingAs($admin)->test(\App\Livewire\Assortment\Index::class)->assertSee('ZZ Closed Shop');
    }
}
