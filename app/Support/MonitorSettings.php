<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Settings of the public (no-login) wall monitor, stored as one JSON row under
 * the `settings` key "monitor". The link token is kept in plaintext on purpose
 * so an admin can copy the link again; it is only ever compared with
 * hash_equals(). Profit is hidden on the public screen unless switched on.
 */
class MonitorSettings
{
    private const KEY = 'monitor';

    private const DEFAULTS = [
        'enabled' => false,
        'token' => null,
        'show_profit' => false,
        'token_created_at' => null,
    ];

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $raw = DB::table('settings')->where('key', self::KEY)->value('value');
        $data = is_string($raw) ? json_decode($raw, true) : null;

        return array_merge(self::DEFAULTS, is_array($data) ? $data : []);
    }

    public function enabled(): bool
    {
        return (bool) $this->all()['enabled'];
    }

    public function setEnabled(bool $enabled): void
    {
        $this->save(['enabled' => $enabled]);
    }

    public function showProfit(): bool
    {
        return (bool) $this->all()['show_profit'];
    }

    public function setShowProfit(bool $show): void
    {
        $this->save(['show_profit' => $show]);
    }

    public function token(): ?string
    {
        $token = $this->all()['token'];

        return is_string($token) && $token !== '' ? $token : null;
    }

    public function tokenCreatedAt(): ?string
    {
        return $this->all()['token_created_at'];
    }

    /**
     * New UUID v4; the previous link stops working immediately. The very first
     * link also switches the public screen on.
     */
    public function generateToken(): string
    {
        $first = $this->token() === null;
        $token = (string) Str::uuid();

        $changes = ['token' => $token, 'token_created_at' => now()->toDateTimeString()];
        if ($first) {
            $changes['enabled'] = true;
        }
        $this->save($changes);

        return $token;
    }

    /** True only while the screen is enabled and the token is the current one. */
    public function accepts(?string $candidate): bool
    {
        $token = $this->token();

        return $this->enabled()
            && $token !== null
            && is_string($candidate)
            && $candidate !== ''
            && hash_equals($token, $candidate);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function save(array $changes): void
    {
        $data = array_merge($this->all(), $changes);

        DB::table('settings')->updateOrInsert(
            ['key' => self::KEY],
            ['value' => json_encode($data), 'updated_at' => now(), 'created_at' => now()]
        );
    }
}
