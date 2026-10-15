<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-user secret links (order_links), the audit trail of draft changes (order_events) and the Telegram outcome on
     * the order. The old shared orders.access_token is replaced: for every DRAFT whose shop has exactly ONE active
     * assigned operator the old token becomes that operator's link (so links already sent keep working and are now
     * attributed to him); every other old token is dropped (the admin copies / resends per-user links).
     */
    public function up(): void
    {
        Schema::create('order_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token', 36)->unique();
            $table->timestamp('telegram_sent_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('last_used_at')->nullable();

            $table->unique(['order_id', 'user_id']);
        });

        Schema::create('order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // link | panel | system
            $table->string('channel', 8);
            $table->string('action', 24);
            $table->foreignId('item_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('old_qty', 10, 3)->nullable();
            $table->decimal('new_qty', 10, 3)->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['order_id', 'id']);
        });

        Schema::table('orders', function (Blueprint $table) {
            // sent | failed | no_recipients | no_token; null = never tried
            $table->string('telegram_status', 16)->nullable();
            $table->timestamp('telegram_sent_at')->nullable();
            $table->string('telegram_note', 255)->nullable();
            $table->unsignedSmallInteger('telegram_recipients_ok')->default(0);
        });

        $drafts = DB::table('orders')->where('status', 'draft')->whereNotNull('access_token')->get(['id', 'shop_id', 'access_token']);
        foreach ($drafts as $draft) {
            $operators = DB::table('shop_user')
                ->join('users', 'users.id', '=', 'shop_user.user_id')
                ->where('shop_user.shop_id', $draft->shop_id)
                ->where('users.role', 'operator')
                ->where('users.is_active', true)
                ->pluck('users.id');
            if ($operators->count() === 1) {
                DB::table('order_links')->insert([
                    'order_id' => $draft->id,
                    'user_id' => $operators->first(),
                    'token' => $draft->access_token,
                    'created_at' => now(),
                ]);
            }
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['access_token']);
            $table->dropColumn('access_token');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('access_token', 36)->nullable()->unique();
            $table->dropColumn(['telegram_status', 'telegram_sent_at', 'telegram_note', 'telegram_recipients_ok']);
        });
        Schema::dropIfExists('order_events');
        Schema::dropIfExists('order_links');
    }
};
