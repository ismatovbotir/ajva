<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Receipt data health:
     *  - receipts.ingested_at  when the receipt was FIRST stored by us (set on the first insert only, never on a resend), so
     *    late / backfilled receipts can be measured. Old rows stay NULL on purpose (the time is unknown, never faked).
     *  - receipts.dq_flags     comma list of data-quality flag codes written at ingestion (NULL = nothing suspicious).
     *  - receipt_rejections    the newest rejected receipts (till 422 and history batch), capped by the app.
     *  - receipt_rejection_counts  rejected receipts per day and reason (small, kept for the board).
     * The (shop_id, ingested_at) index on a big receipts table takes time: run the migration outside the busy hours.
     */
    public function up(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            $table->timestamp('ingested_at')->nullable();
            $table->text('dq_flags')->nullable();
            $table->index(['shop_id', 'ingested_at'], 'receipts_shop_ingested_idx');
        });

        Schema::create('receipt_rejections', function (Blueprint $table) {
            $table->id();
            $table->string('source', 12)->default('pos');
            $table->unsignedBigInteger('shop_id')->nullable();
            $table->unsignedBigInteger('pos_id')->nullable();
            $table->integer('session')->nullable();
            $table->string('number')->nullable();
            $table->string('reason', 24);
            $table->unsignedBigInteger('item_id')->nullable();
            $table->string('detail', 255)->nullable();
            $table->timestamp('created_at')->nullable()->index();
            $table->index(['reason', 'created_at']);
        });

        Schema::create('receipt_rejection_counts', function (Blueprint $table) {
            $table->date('stat_date');
            $table->string('reason', 24);
            $table->unsignedInteger('total')->default(0);
            $table->primary(['stat_date', 'reason']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_rejection_counts');
        Schema::dropIfExists('receipt_rejections');

        Schema::table('receipts', function (Blueprint $table) {
            $table->dropIndex('receipts_shop_ingested_idx');
            $table->dropColumn(['ingested_at', 'dq_flags']);
        });
    }
};
