<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Close the loop of a confirmed order (the shop confirms the goods arrived) and the idempotent log of the
     * Telegram reminders sent about orders.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('received_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            // received | expired | cancelled (null = still open)
            $table->string('close_reason', 20)->nullable();
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('qty_received', 10, 3)->nullable();
            // damaged | missing | extra | other (only when qty_received differs from qty)
            $table->string('discrepancy_reason', 20)->nullable();
        });

        Schema::create('order_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('shop_id')->nullable();
            $table->string('kind', 40);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('sent_at');

            $table->unique(['order_id', 'kind', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_reminders');

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['qty_received', 'discrepancy_reason']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('received_by');
            $table->dropColumn(['received_at', 'close_reason']);
        });
    }
};
