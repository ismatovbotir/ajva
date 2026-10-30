<?php

namespace App\Livewire\Concerns;

use App\Models\Barcode;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\OrderItem;
use Illuminate\Support\Collection;

/**
 * The draft-editing actions shared by the signed-in order page (Orders\Show, policy `edit`) and the public
 * link page (Orders\PublicDraft, personal link): quantity change, remove a line, add an item. The using
 * component supplies draftOrder(), which must return the order only if the caller may edit it right now
 * (a draft) and abort (403/404) otherwise, on every call, plus the actor (actorId / channel) every change is
 * recorded for in order_events.
 */
trait EditsDraftOrder
{
    public string $search = '';

    public string $addQty = '1';

    /** The order being edited, authorized for editing on this very request. */
    abstract protected function draftOrder(): Order;

    /** Who is acting: the signed-in user, or the owner of the personal link. */
    abstract protected function actorId(): ?int;

    /** OrderEvent::CHANNEL_PANEL or OrderEvent::CHANNEL_LINK. */
    abstract protected function channel(): string;

    /** The responsible person's free-text comment (loaded in mount, saved on blur and before confirming). */
    public string $comment = '';

    private function logEvent(Order $order, string $action, ?int $itemId = null, ?float $old = null, ?float $new = null, ?string $note = null): void
    {
        OrderEvent::record($order, $this->actorId(), $this->channel(), $action, $itemId, $old, $new, $note);
    }

    /** Stores the comment on the (editable) order and records the change; no-op when nothing changed. */
    private function persistComment(Order $order): void
    {
        $text = trim(strip_tags($this->comment));
        if (mb_strlen($text) > 1000) {
            $this->addError('comment', __('The comment can be at most :max characters.', ['max' => 1000]));

            return;
        }
        $this->resetErrorBag('comment');

        if ($text === (string) $order->comment) {
            return;
        }

        $order->forceFill(['comment' => $text === '' ? null : $text])->save();
        $this->logEvent($order, OrderEvent::COMMENT, null, null, null, $text === '' ? __('removed') : mb_substr($text, 0, 255));
    }

    public function saveComment(): void
    {
        $this->persistComment($this->draftOrder());
    }

    /** Server-side check of a typed quantity: a number above 0 and sane. */
    private function validQty(mixed $value): ?float
    {
        if (! is_numeric($value) || (float) $value <= 0 || (float) $value > 1000000) {
            $this->addError('qty', __('The quantity must be a number above 0.'));

            return null;
        }

        return round((float) $value, 3);
    }

    public function saveQty(int $itemId, mixed $value): void
    {
        $order = $this->draftOrder();

        if (($qty = $this->validQty($value)) !== null) {
            $this->resetErrorBag('qty');
            $line = $order->items()->where('item_id', $itemId)->first();
            if ($line === null) {
                return;
            }
            $old = (float) $line->qty;
            $line->update(['qty' => $qty]);
            if (abs($old - $qty) > 1e-9) {
                $this->logEvent($order, OrderEvent::QTY_CHANGED, $itemId, $old, $qty);
            }
        }
    }

    public function removeItem(int $itemId): void
    {
        $order = $this->draftOrder();
        $line = $order->items()->where('item_id', $itemId)->first();
        if ($line !== null) {
            $line->delete();
            $this->logEvent($order, OrderEvent::LINE_REMOVED, $itemId, (float) $line->qty);
        }
    }

    /**
     * Items matching the typed text by code (mark / 1C id), GTIN (barcode) or name; needs at least
     * MIN_SEARCH_CHARS characters. Items already in the order are left out.
     *
     * @param  Collection<int, int>  $excludeIds
     * @return Collection<int, Item>
     */
    protected function searchItems(Collection $excludeIds): Collection
    {
        $term = trim($this->search);
        if (mb_strlen($term) < static::minSearchChars()) {
            return collect();
        }

        $escaped = addcslashes($term, '%_\\');
        $like = '%'.$escaped.'%';

        return Item::query()
            ->with('barcodes:id,item_id,gtin')
            ->whereNotIn('id', $excludeIds)
            ->where(function ($q) use ($term, $like, $escaped) {
                $q->where('name', 'like', $like)
                    ->orWhere('mark', 'like', $like)
                    ->orWhereIn('id', Barcode::query()->where('gtin', 'like', $escaped.'%')->select('item_id'));
                if (ctype_digit($term)) {
                    $q->orWhere('id', (int) $term);
                }
            })
            ->orderBy('name')->limit(8)->get(['id', 'name', 'mark']);
    }

    /** Characters needed before the item dropdown appears. */
    public static function minSearchChars(): int
    {
        return 3;
    }

    /** Lines added by the manager first (newest on top), then the system's lines by name. */
    protected function sortLines(Collection $lines): Collection
    {
        return $lines->sortBy(fn (OrderItem $i) => $i->added_manually
            ? [0, -$i->id, '']
            : [1, 0, (string) $i->item?->name])->values();
    }

    public function addItem(int $itemId): void
    {
        $order = $this->draftOrder();

        if (($qty = $this->validQty($this->addQty)) === null) {
            return;
        }
        $item = Item::query()->findOrFail($itemId);
        if ($order->items()->where('item_id', $item->id)->exists()) {
            $this->addError('qty', __('This item is already in the order.'));

            return;
        }

        $order->items()->create([
            'item_id' => $item->id,
            'qty' => $qty,
            'suggested_qty' => null,
            'added_manually' => true,
        ]);
        $this->logEvent($order, OrderEvent::LINE_ADDED, $item->id, null, $qty);
        $this->reset('search');
        $this->resetErrorBag('qty');
    }
}
