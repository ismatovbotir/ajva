<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Support\PublicUrl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The personal secret link of one user to one draft order (/o/{token}). Whatever is done through it is recorded for
 * that user. It works only while the order is a draft AND the user is still active and assigned to the order's shop.
 */
class OrderLink extends Model
{
    public $timestamps = false;

    protected $fillable = ['order_id', 'user_id', 'token', 'telegram_sent_at', 'created_at', 'last_used_at'];

    protected $hidden = ['token'];

    protected $casts = [
        'telegram_sent_at' => 'datetime',
        'created_at' => 'datetime',
        'last_used_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The user's link to the order (created on first use). */
    public static function forUser(Order $order, User $user): self
    {
        return self::query()->firstOrCreate(
            ['order_id' => $order->id, 'user_id' => $user->id],
            ['token' => (string) Str::uuid(), 'created_at' => now()],
        );
    }

    public function url(): string
    {
        return PublicUrl::to('o/'.$this->token);
    }

    /** Is this user a responsible person of the shop right now (active operator assigned to it)? */
    public static function mayAct(User $user, int $shopId): bool
    {
        return $user->is_active
            && $user->role === UserRole::Operator
            && DB::table('shop_user')->where('shop_id', $shopId)->where('user_id', $user->id)->exists();
    }

    /**
     * Token -> link whose order is a draft and whose user may still act, else null. The indexed lookup is followed by
     * a constant-time comparison.
     */
    public static function resolve(string $token, bool $touch = false): ?self
    {
        $link = self::query()->where('token', $token)->first();
        if ($link === null || ! hash_equals((string) $link->token, $token)) {
            return null;
        }

        $order = Order::query()->find($link->order_id);
        $user = User::query()->find($link->user_id);
        if ($order === null || $user === null || $order->status !== OrderStatus::Draft || ! self::mayAct($user, $order->shop_id)) {
            return null;
        }

        $link->setRelation('order', $order);
        $link->setRelation('user', $user);

        if ($touch && ($link->last_used_at === null || $link->last_used_at->lt(now()->subMinute()))) {
            self::query()->whereKey($link->id)->update(['last_used_at' => now()]);
        }

        return $link;
    }
}
