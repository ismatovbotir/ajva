<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Delivery log of the order notifications: which order, to which panel user, whether Telegram accepted it
     * (the Bot API has no "delivered / read" status) and the error text when it did not.
     */
    public function up(): void
    {
        Schema::table('telegram_messages', function (Blueprint $table) {
            $table->foreignId('order_id')->nullable()->after('sent_by')->constrained('orders')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->after('order_id')->constrained('users')->nullOnDelete();
            $table->string('status', 10)->default('sent')->after('type'); // sent | failed
            $table->string('error', 250)->nullable()->after('status');

            $table->index(['order_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('telegram_messages', function (Blueprint $table) {
            $table->dropIndex(['order_id', 'user_id']);
            $table->dropConstrainedForeignId('order_id');
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn(['status', 'error']);
        });
    }
};
