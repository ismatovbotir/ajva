<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Replenishment orders from the main warehouse to a shop (prepared by the Ordering page).
     */
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained();
            $table->foreignId('warehouse_id')->nullable()->constrained('shops');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('confirmed_by')->nullable()->constrained('users');
            // OrderStatus enum: draft, pending_approval, approved, confirmed, rejected, fulfilled, cancelled
            $table->string('status', 32)->default('draft');
            // Where the order came from, e.g. "ordering_report"
            $table->string('origin', 32)->nullable();
            $table->unsignedTinyInteger('horizon_days')->nullable();
            // Sync to 1C, separate from the status: not_queued | queued | synced | failed
            $table->string('sync_status', 16)->default('not_queued');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->unsignedSmallInteger('sync_attempts')->default(0);
            $table->text('sync_error')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'status']);
            $table->index('sync_status');
            $table->index('created_at');
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained();
            $table->decimal('qty', 10, 3);
            // What the forecast asked for (qty can be lower when the warehouse is short)
            $table->decimal('suggested_qty', 10, 3)->nullable();
            $table->unsignedTinyInteger('horizon_days')->nullable();
            // Added by hand on the order (suggested_qty and the figures below stay null)
            $table->boolean('added_manually')->default(false);
            // Forecast figures the suggestion was based on (audit): estimated stock, min rule, sales per day, cover days
            $table->decimal('est', 10, 3)->nullable();
            $table->decimal('min', 10, 3)->nullable();
            $table->decimal('speed', 10, 3)->nullable();
            $table->decimal('cover_days', 8, 1)->nullable();
            $table->timestamps();

            $table->unique(['order_id', 'item_id']);
            $table->index('item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
