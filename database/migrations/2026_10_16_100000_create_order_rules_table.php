<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Per-shop order rules (the row with shop_id NULL is the chain default; a shop row only overrides the
     * columns it sets, NULL = inherit), the secret link of a draft and the data the Telegram message needs.
     */
    public function up(): void
    {
        Schema::create('order_rules', function (Blueprint $table) {
            $table->id();
            // NULL = the chain default row. Unique per shop (NULLs do not collide, the default row is kept single in code).
            $table->foreignId('shop_id')->nullable()->unique()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->nullable();
            $table->unsignedTinyInteger('history_days')->nullable();
            $table->unsignedTinyInteger('horizon_days')->nullable();
            $table->string('run_time', 5)->nullable();
            // List of weekdays 0 (Sunday) - 6 (Saturday) as JSON text; [] = every day, NULL = inherit
            $table->text('weekdays')->nullable();
            $table->string('telegram_chat_id', 40)->nullable();
            // Result of the last automatic run (written by orders:auto-generate only)
            $table->date('last_run_date')->nullable();
            $table->string('last_run_status', 32)->nullable();
            $table->text('last_run_note')->nullable();
            $table->timestamps();
        });

        Schema::table('orders', function (Blueprint $table) {
            // Secret link /o/{token} of a draft; stops working when the order is no longer a draft
            $table->string('access_token', 36)->nullable()->unique();
            // Short summary of what the automatic run left out (stale=3,none=5,capped=1), for the Telegram message
            $table->string('warnings', 191)->nullable();
        });

        // Drafts that already exist get their link token too
        foreach (DB::table('orders')->where('status', 'draft')->pluck('id') as $id) {
            DB::table('orders')->where('id', $id)->update(['access_token' => (string) Str::uuid()]);
        }

        Schema::table('order_items', function (Blueprint $table) {
            // Comma list of markers for the Telegram message: out, short, understated, low
            $table->string('flags', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('flags');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['access_token']);
            $table->dropColumn(['access_token', 'warnings']);
        });
        Schema::dropIfExists('order_rules');
    }
};
