<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained();
            $table->foreignId('shop_id')->constrained();
            $table->decimal('qty', 10, 3)->default(0);
            // Daily history: one row per (item, shop, sync day). "Current" stock is the
            // row with the greatest stock_date per (item, shop) - see Stock::scopeCurrent().
            $table->date('stock_date');
            $table->unique(['item_id', 'shop_id', 'stock_date']);
            $table->index(['shop_id', 'stock_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stocks');
    }
};
