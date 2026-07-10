<?php

namespace App\Livewire\Items;

use App\Models\Barcode;
use App\Models\Item;
use App\Models\ItemOrderRule;
use App\Models\ItemPrice;
use App\Models\Price;
use App\Models\Shop;
use App\Models\Stock;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app', ['title' => 'Item'])]
class Show extends Component
{
    public Item $item;

    // Barcodes
    public bool $showBarcodeModal = false;

    public ?int $barcodeId = null;

    public string $gtin = '';

    // Item prices
    public bool $showItemPriceModal = false;

    public ?int $itemPriceId = null;

    public ?int $price_id = null;

    public string $value = '0';

    // Order rules
    public bool $showOrderRuleModal = false;

    public ?int $orderRuleId = null;

    public ?int $shop_id = null;

    public string $min = '0';

    public string $max = '0';

    // Stock
    public bool $showStockModal = false;

    public ?int $stockId = null;

    public ?int $stock_shop_id = null;

    public string $stock_qty = '0';

    public function mount(Item $item): void
    {
        $this->item = $item;
    }

    // --- Barcodes ---

    public function createBarcode(): void
    {
        $this->resetBarcodeForm();
        $this->showBarcodeModal = true;
    }

    public function editBarcode(Barcode $barcode): void
    {
        $this->barcodeId = $barcode->id;
        $this->gtin = $barcode->gtin;
        $this->showBarcodeModal = true;
    }

    public function saveBarcode(): void
    {
        $data = $this->validate([
            'gtin' => [
                'required',
                'string',
                'max:50',
                Rule::unique('barcodes', 'gtin')->ignore($this->barcodeId),
            ],
        ], [
            'gtin.unique' => __('This barcode is already assigned to another item.'),
        ]);

        $data['item_id'] = $this->item->id;

        Barcode::query()->updateOrCreate(['id' => $this->barcodeId], $data);

        $this->closeBarcodeModal();
    }

    public function deleteBarcode(Barcode $barcode): void
    {
        $barcode->delete();
    }

    public function closeBarcodeModal(): void
    {
        $this->showBarcodeModal = false;
        $this->resetBarcodeForm();
    }

    private function resetBarcodeForm(): void
    {
        $this->reset(['barcodeId', 'gtin']);
        $this->resetErrorBag();
    }

    // --- Item prices ---

    public function createItemPrice(): void
    {
        $this->resetItemPriceForm();
        $this->showItemPriceModal = true;
    }

    public function editItemPrice(ItemPrice $itemPrice): void
    {
        $this->itemPriceId = $itemPrice->id;
        $this->price_id = $itemPrice->price_id;
        $this->value = (string) $itemPrice->value;
        $this->showItemPriceModal = true;
    }

    public function saveItemPrice(): void
    {
        $data = $this->validate([
            'price_id' => [
                'required',
                'exists:prices,id',
                Rule::unique('item_prices', 'price_id')
                    ->where(fn ($query) => $query->where('item_id', $this->item->id))
                    ->ignore($this->itemPriceId),
            ],
            'value' => ['required', 'numeric', 'min:0'],
        ], [
            'price_id.unique' => __('This item already has a value for that price type.'),
        ]);

        $data['item_id'] = $this->item->id;

        ItemPrice::query()->updateOrCreate(['id' => $this->itemPriceId], $data);

        $this->closeItemPriceModal();
    }

    public function deleteItemPrice(ItemPrice $itemPrice): void
    {
        $itemPrice->delete();
    }

    public function closeItemPriceModal(): void
    {
        $this->showItemPriceModal = false;
        $this->resetItemPriceForm();
    }

    private function resetItemPriceForm(): void
    {
        $this->reset(['itemPriceId', 'price_id']);
        $this->value = '0';
        $this->resetErrorBag();
    }

    // --- Order rules ---

    public function createOrderRule(): void
    {
        $this->resetOrderRuleForm();
        $this->showOrderRuleModal = true;
    }

    public function editOrderRule(ItemOrderRule $orderRule): void
    {
        $this->orderRuleId = $orderRule->id;
        $this->shop_id = $orderRule->shop_id;
        $this->min = (string) $orderRule->min;
        $this->max = (string) $orderRule->max;
        $this->showOrderRuleModal = true;
    }

    public function saveOrderRule(): void
    {
        $data = $this->validate([
            'shop_id' => [
                'required',
                'exists:shops,id',
                Rule::unique('item_order_rules', 'shop_id')
                    ->where(fn ($query) => $query->where('item_id', $this->item->id))
                    ->ignore($this->orderRuleId),
            ],
            'min' => ['required', 'numeric', 'min:0'],
            'max' => ['required', 'numeric', 'min:0'],
        ], [
            'shop_id.unique' => __('This item already has an order rule for that shop.'),
        ]);

        $data['item_id'] = $this->item->id;

        ItemOrderRule::query()->updateOrCreate(['id' => $this->orderRuleId], $data);

        $this->closeOrderRuleModal();
    }

    public function deleteOrderRule(ItemOrderRule $orderRule): void
    {
        $orderRule->delete();
    }

    public function closeOrderRuleModal(): void
    {
        $this->showOrderRuleModal = false;
        $this->resetOrderRuleForm();
    }

    private function resetOrderRuleForm(): void
    {
        $this->reset(['orderRuleId', 'shop_id']);
        $this->min = '0';
        $this->max = '0';
        $this->resetErrorBag();
    }

    // --- Stock ---

    public function createStock(): void
    {
        $this->resetStockForm();
        $this->showStockModal = true;
    }

    public function editStock(Stock $stock): void
    {
        $this->stockId = $stock->id;
        $this->stock_shop_id = $stock->shop_id;
        $this->stock_qty = (string) $stock->qty;
        $this->showStockModal = true;
    }

    public function saveStock(): void
    {
        $this->validate([
            'stock_shop_id' => [
                'required',
                'exists:shops,id',
                Stock::shopUniqueRule($this->item->id, $this->stockId),
            ],
            'stock_qty' => ['required', 'numeric', 'min:0'],
        ], [
            'stock_shop_id.unique' => __('Stock for this item and shop combination already exists.'),
        ]);

        Stock::query()->updateOrCreate(['id' => $this->stockId], [
            'item_id' => $this->item->id,
            'shop_id' => $this->stock_shop_id,
            'qty' => $this->stock_qty,
        ]);

        $this->closeStockModal();
    }

    public function deleteStock(Stock $stock): void
    {
        $stock->delete();
    }

    public function closeStockModal(): void
    {
        $this->showStockModal = false;
        $this->resetStockForm();
    }

    private function resetStockForm(): void
    {
        $this->reset(['stockId', 'stock_shop_id']);
        $this->stock_qty = '0';
        $this->resetErrorBag();
    }

    public function render()
    {
        return view('livewire.items.show', [
            'barcodes' => $this->item->barcodes()->orderBy('id')->get(),
            'itemPrices' => $this->item->itemPrices()->with('price')->orderBy('id')->get(),
            'orderRules' => $this->item->orderRules()->with('shop')->orderBy('id')->get(),
            'stocks' => $this->item->stocks()->with('shop')->orderBy('id')->get(),
            'prices' => Price::query()->orderBy('name')->get(),
            'shops' => Shop::query()->orderBy('name')->get(),
        ]);
    }
}
