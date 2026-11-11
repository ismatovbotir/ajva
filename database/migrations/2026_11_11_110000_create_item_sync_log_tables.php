<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Log of the 1C items sync (POST /api/items): ONE row per day (the update time + counters, no per-batch success
 * rows) and one row per ERROR event only (newest N kept, see inventory.item_sync_keep_errors).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_sync_days', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->dateTime('last_success_at')->nullable();
            $table->unsignedInteger('batches')->default(0);
            $table->unsignedInteger('items')->default(0);
            $table->unsignedInteger('error_count')->default(0);
        });

        Schema::create('item_sync_errors', function (Blueprint $table) {
            $table->id();
            $table->dateTime('occurred_at')->index();
            $table->string('stage', 20);
            $table->string('message', 500);
            $table->unsignedBigInteger('item_id')->nullable();
            $table->unsignedBigInteger('shop_id')->nullable();
            $table->unsignedInteger('batch_size')->nullable();
            $table->string('excerpt', 200)->nullable();
            $table->string('ip', 45)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_sync_errors');
        Schema::dropIfExists('item_sync_days');
    }
};
