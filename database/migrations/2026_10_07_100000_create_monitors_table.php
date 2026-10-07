<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Several admin-configured monitors (executive / receipts / warehouse), each
     * showing a chosen set of shops (no rows in monitor_shop = all shops).
     */
    public function up(): void
    {
        Schema::create('monitors', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('type', 20);
            $table->string('token', 36)->nullable()->unique();
            $table->boolean('enabled')->default(true);
            $table->boolean('show_profit')->default(false);
            $table->timestamps();
        });

        Schema::create('monitor_shop', function (Blueprint $table) {
            $table->foreignId('monitor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->primary(['monitor_id', 'shop_id']);
        });

        $this->convertOldPublicMonitor();
    }

    /**
     * The former single public monitor lived in settings.key = 'monitor'. Keep
     * its link working as an executive monitor, then drop the old row.
     */
    private function convertOldPublicMonitor(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        $raw = DB::table('settings')->where('key', 'monitor')->value('value');
        $old = is_string($raw) ? json_decode($raw, true) : null;

        if (is_array($old) && is_string($old['token'] ?? null) && $old['token'] !== '') {
            DB::table('monitors')->insert([
                'name' => 'Office monitor',
                'type' => 'executive',
                'token' => $old['token'],
                'enabled' => (bool) ($old['enabled'] ?? false),
                'show_profit' => (bool) ($old['show_profit'] ?? false),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('settings')->where('key', 'monitor')->delete();
    }

    public function down(): void
    {
        Schema::dropIfExists('monitor_shop');
        Schema::dropIfExists('monitors');
    }
};
