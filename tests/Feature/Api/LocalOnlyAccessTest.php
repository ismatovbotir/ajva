<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Who may call the local-network-only 1C endpoints (GET /api/receipts/last is the probe). */
class LocalOnlyAccessTest extends TestCase
{
    use RefreshDatabase;

    private function callFrom(string $ip, int $expect): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => $ip])->getJson('/api/receipts/last')->assertStatus($expect);
    }

    public function test_loopback_private_and_ipv6_local_addresses_are_allowed(): void
    {
        foreach (['127.0.0.1', '10.20.30.40', '172.16.5.5', '172.31.255.1', '192.168.1.77', '169.254.10.1', '::1', 'fd12:3456::1', 'fe80::1', '::ffff:192.168.0.9'] as $ip) {
            $this->callFrom($ip, 200);
        }
    }

    public function test_public_and_out_of_range_addresses_are_refused_and_the_message_names_the_ip(): void
    {
        foreach (['8.8.8.8', '172.32.0.1', '172.15.0.1', '203.0.113.9', '100.64.1.1', '2001:db8::1'] as $ip) {
            $this->callFrom($ip, 403);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->getJson('/api/receipts/last')
            ->assertForbidden()->assertJsonPath('message', fn ($m) => str_contains($m, '203.0.113.9') && str_contains($m, 'LOCAL_API_ALLOWED_IPS'));
    }

    public function test_extra_ips_and_ranges_from_the_env_list_are_allowed(): void
    {
        config(['services.local_api.allowed' => '203.0.113.9, 100.64.0.0/10 ,2001:db8::/32']);

        $this->callFrom('203.0.113.9', 200);       // a single address
        $this->callFrom('100.127.255.254', 200);   // inside the range (a VPN / CGNAT network)
        $this->callFrom('2001:db8::77', 200);      // an IPv6 range
        $this->callFrom('203.0.113.10', 403);      // next address is not on the list
        $this->callFrom('100.128.0.1', 403);       // just outside the /10
        $this->callFrom('8.8.8.8', 403);
    }

    public function test_the_same_rule_protects_the_items_sync_and_the_batch_endpoint(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->postJson('/api/items', [])->assertForbidden();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->postJson('/api/receipts/batch', [])->assertForbidden();

        config(['services.local_api.allowed' => '203.0.113.9']);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->postJson('/api/receipts/batch', [])->assertOk();
    }
}
