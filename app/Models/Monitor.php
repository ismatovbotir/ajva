<?php

namespace App\Models;

use App\Enums\MonitorType;
use App\Support\PublicUrl;
use App\Support\ShopAccess;
use App\Support\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * An admin-configured wall/TV screen: a type (executive, receipts, warehouse),
 * the shops it shows (none selected = all shops) and an optional secret public
 * link. The token is plaintext on purpose so the admin can copy the link again;
 * it is only ever compared with hash_equals().
 */
class Monitor extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'type', 'token', 'enabled', 'show_profit'];

    protected $casts = [
        'type' => MonitorType::class,
        'enabled' => 'boolean',
        'show_profit' => 'boolean',
    ];

    public function shops(): BelongsToMany
    {
        return $this->belongsToMany(Shop::class);
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    /** The monitor behind a public token, only while it exists and is enabled. */
    public static function findPublic(?string $token): ?self
    {
        if (! is_string($token) || $token === '') {
            return null;
        }

        $monitor = static::query()->enabled()->where('token', $token)->first();

        return $monitor !== null && hash_equals((string) $monitor->token, $token) ? $monitor : null;
    }

    /** New UUID v4; the previous link stops working immediately. */
    public function regenerateToken(): string
    {
        $this->forceFill(['token' => (string) Str::uuid()])->save();

        return $this->token;
    }

    public function publicLink(): ?string
    {
        return $this->token ? PublicUrl::to(route('monitor.public', ['token' => $this->token], false)) : null;
    }

    /**
     * The shops this monitor shows: null = every shop (nothing selected), else
     * an id list. The warehouse type never includes the main warehouse shop, so
     * for it "all shops" becomes an explicit list without that shop.
     *
     * @return array<int, int>|null
     */
    public function shopIds(): ?array
    {
        $selected = $this->shops()->pluck('shops.id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $warehouseId = $this->type->excludesWarehouse() ? Warehouse::shopId() : null;

        if ($selected === []) {
            if ($warehouseId === null) {
                return null;
            }

            return Shop::query()->where('id', '!=', $warehouseId)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        return array_values(array_filter($selected, fn ($id) => $id !== $warehouseId));
    }

    /**
     * Monitors a user may open: admins all, everyone else only enabled ones; a
     * shop-restricted operator only those whose shops overlap their own (a
     * monitor showing all shops always qualifies).
     *
     * @return \Illuminate\Support\Collection<int, Monitor>
     */
    public static function openableBy(User $user)
    {
        $monitors = static::query()->with('shops:id,name')
            ->when(! $user->isAdmin(), fn ($q) => $q->enabled())
            ->orderBy('name')->orderBy('id')->get();

        $allowed = ShopAccess::ids($user);
        if ($allowed === null) {
            return $monitors;
        }

        return $monitors->filter(function (self $m) use ($allowed) {
            $selected = $m->shops->pluck('id')->map(fn ($id) => (int) $id)->all();

            return $selected === [] || array_intersect($selected, $allowed) !== [];
        })->values();
    }
}
