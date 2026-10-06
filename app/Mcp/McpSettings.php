<?php

namespace App\Mcp;

use Illuminate\Support\Facades\DB;

/**
 * MCP server settings managed from the admin panel, stored as one JSON row in
 * the `settings` table. Only a SHA-256 hash of the token is stored; the
 * plaintext is shown once when generated. The MCP_API_TOKEN env value keeps
 * working as an additional accepted token.
 */
class McpSettings
{
    private const KEY = 'mcp';

    private const DEFAULTS = [
        'enabled' => true,
        'token_hash' => null,
        'token_created_at' => null,
        'disabled_tools' => [],
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

    public function hasStoredToken(): bool
    {
        return (bool) $this->all()['token_hash'];
    }

    public function hasEnvToken(): bool
    {
        return (string) config('mcp.token') !== '';
    }

    public function tokenCreatedAt(): ?string
    {
        return $this->all()['token_created_at'];
    }

    /**
     * Create a new token, replacing (and so invalidating) the previous stored
     * one. Returns the plaintext, which is never stored.
     */
    public function generateToken(): string
    {
        $token = bin2hex(random_bytes(24));

        $this->save([
            'token_hash' => hash('sha256', $token),
            'token_created_at' => now()->toDateTimeString(),
        ]);

        return $token;
    }

    public function matchesToken(string $token): bool
    {
        if ($token === '') {
            return false;
        }

        $hash = $this->all()['token_hash'];
        if ($hash && hash_equals($hash, hash('sha256', $token))) {
            return true;
        }

        $env = (string) config('mcp.token');

        return $env !== '' && hash_equals($env, $token);
    }

    public function toolEnabled(string $name): bool
    {
        return ! in_array($name, $this->all()['disabled_tools'], true);
    }

    public function setToolEnabled(string $name, bool $enabled): void
    {
        $disabled = array_values(array_diff($this->all()['disabled_tools'], [$name]));
        if (! $enabled) {
            $disabled[] = $name;
        }

        $this->save(['disabled_tools' => array_values(array_unique($disabled))]);
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
