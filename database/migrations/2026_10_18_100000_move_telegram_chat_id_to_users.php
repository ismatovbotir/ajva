<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Telegram chat id belongs to the person, not to the order rule: users.telegram_chat_id. A chat id that was
     * set on a shop's rule moves to the shop's operator only when that is unambiguous (exactly one operator is
     * assigned to the shop and he has none yet); the default row's and every other value is dropped.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('telegram_chat_id', 40)->nullable();
        });

        $rules = DB::table('order_rules')->whereNotNull('shop_id')->whereNotNull('telegram_chat_id')->get(['shop_id', 'telegram_chat_id']);
        foreach ($rules as $rule) {
            $chat = trim((string) $rule->telegram_chat_id);
            if ($chat === '') {
                continue;
            }
            $operators = DB::table('shop_user')
                ->join('users', 'users.id', '=', 'shop_user.user_id')
                ->where('shop_user.shop_id', $rule->shop_id)
                ->where('users.role', 'operator')
                ->pluck('users.telegram_chat_id', 'users.id');
            if ($operators->count() === 1 && trim((string) $operators->first()) === '') {
                DB::table('users')->where('id', $operators->keys()->first())->update(['telegram_chat_id' => $chat]);
            }
        }

        Schema::table('order_rules', function (Blueprint $table) {
            $table->dropColumn('telegram_chat_id');
        });
    }

    public function down(): void
    {
        Schema::table('order_rules', function (Blueprint $table) {
            $table->string('telegram_chat_id', 40)->nullable();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('telegram_chat_id');
        });
    }
};
