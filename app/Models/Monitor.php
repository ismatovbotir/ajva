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

    protected $fillable = ['name', 'type', 'token', 'enabled', 'show_profit', 'description', 'layout', 'layout_version', 'is_template', 'roles', 'layout_rows'];

    protected $casts = [
        'type' => MonitorType::class,
        'enabled' => 'boolean',
        'show_profit' => 'boolean',
        'is_template' => 'boolean',
        'layout' => 'array',
        'roles' => 'array',
        'layout_version' => 'integer',
        'layout_rows' => 'integer',
    ];

    public function shops(): BelongsToMany
    {
        return $this->belongsToMany(Shop::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    /** Templates are reusable layouts, never screens: they have no link and are not listed to viewers. */
    public function scopeScreens(Builder $query): Builder
    {
        return $query->where('is_template', false);
    }

    /** @return array<int, array<string, mixed>> the saved widget list (custom type) */
    public function widgets(): array
    {
        return is_array($this->layout) ? array_values($this->layout) : [];
    }

    /** Allowed row-density settings of the screen (null = as many rows as the layout uses). */
    public const ROW_CHOICES = [8, 12, 18];

    /**
     * How many rows the screen is divided into: the fixed density when set, never fewer than the layout needs.
     * Shared by the real screen and the builder canvas, so both draw the same row height.
     */
    public function gridRows(?array $widgets = null): int
    {
        $used = 1;
        foreach ($widgets ?? $this->widgets() as $w) {
            $used = max($used, (int) ($w['y'] ?? 0) + (int) ($w['h'] ?? 1));
        }

        return max($used, (int) $this->layout_rows);
    }

    /**
     * Who may open the screen: admins always; with no roles AND no users selected every
     * role that may open monitors; otherwise only a listed role or a listed user.
     */
    public function allowsUser(User $user): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        $roles = is_array($this->roles) ? $this->roles : [];
        $userIds = $this->relationLoaded('users') ? $this->users->pluck('id') : $this->users()->pluck('users.id');

        if ($roles === [] && $userIds->isEmpty()) {
            return true;
        }

        return in_array($user->role->value, $roles, true) || $userIds->contains($user->id);
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

        $monitor = static::query()->enabled()->screens()->where('token', $token)->first();

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
        $monitors = static::query()->with(['shops:id,name', 'users:id'])->screens()
            ->when(! $user->isAdmin(), fn ($q) => $q->enabled())
            ->orderBy('name')->orderBy('id')->get()
            ->filter(fn (self $m) => $m->allowsUser($user))->values();

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
